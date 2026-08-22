<?php

namespace App\Services\YandexDirect;

use App\Models\YandexDirectSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YandexDirectService
{
    private YandexDirectSetting $settings;

    public function __construct(?YandexDirectSetting $settings = null)
    {
        // Контейнер Laravel может автоматически создать пустую модель для
        // nullable-зависимости. Используем её только если она реально
        // загружена из БД, иначе берём сохранённые настройки интеграции.
        $this->settings = $settings?->exists ? $settings : YandexDirectSetting::current();
    }

    public function testConnection(): array
    {
        $campaign = $this->getCampaign();
        $feed = $this->getFeed();
        $type = strtoupper((string) ($campaign['Type'] ?? ''));
        if ($type !== 'UNIFIED_CAMPAIGN') {
            throw new RuntimeException('Указанная кампания не является поддерживаемой ЕПК. Тип: ' . ($type ?: 'не определён'));
        }
        $strategy=$campaign['UnifiedCampaign']['BiddingStrategy']??[];$search=$strategy['Search']??[];$network=$strategy['Network']??[];
        $searchCeiling=$search['WbMaximumClicks']['BidCeiling']??null;$networkCeiling=$network['WbMaximumClicks']['BidCeiling']??null;
        return ['api' => true, 'campaign' => ['id' => $campaign['Id'], 'name' => $campaign['Name'] ?? null, 'type' => $type], 'strategy'=>['search_type'=>$search['BiddingStrategyType']??null,'network_type'=>$network['BiddingStrategyType']??null,'search_bid_ceiling'=>$searchCeiling===null?null:$searchCeiling/1000000,'network_bid_ceiling'=>$networkCeiling===null?null:$networkCeiling/1000000,'search_places'=>$search['PlacementTypes']??[],'network_places'=>$network['PlacementTypes']??[]], 'feed' => ['id' => $feed['Id'], 'name' => $feed['Name'] ?? null]];
    }

    public function getCampaign(): array
    {
        $rows = $this->call('campaigns', 'get', ['SelectionCriteria' => ['Ids' => [(int) $this->settings->campaign_id]], 'FieldNames' => ['Id', 'Name', 'Type', 'State'], 'UnifiedCampaignFieldNames'=>['BiddingStrategy']]);
        return $rows['Campaigns'][0] ?? throw new RuntimeException('Кампания #' . $this->settings->campaign_id . ' не найдена.');
    }

    public function getFeed(): array
    {
        $rows = $this->call('feeds', 'get', ['SelectionCriteria' => ['Ids' => [(int) $this->settings->feed_id]], 'FieldNames' => ['Id', 'Name', 'BusinessType', 'SourceType']]);
        return $rows['Feeds'][0] ?? throw new RuntimeException('Feed #' . $this->settings->feed_id . ' недоступен.');
    }

    public function createSellerAdGroup(int $sellerId): int
    {
        $result = $this->call('adgroups', 'add', ['AdGroups' => [['Name' => 'SANCAN_SELLER_' . $sellerId, 'CampaignId' => (int) $this->settings->campaign_id, 'RegionIds' => [225], 'UnifiedAdGroup' => ['OfferRetargeting' => 'NO']]]]);
        return (int) ($result['AddResults'][0]['Id'] ?? throw new RuntimeException('Direct не вернул ID рекламной группы.'));
    }

    public function createShoppingAd(int $adGroupId, array $productIds): int
    {
        $result = $this->call('ads', 'add', ['Ads' => [['AdGroupId' => $adGroupId, 'ShoppingAd' => ['FeedId' => (int) $this->settings->feed_id, 'FeedFilterConditions' => $this->buildFeedFilter($productIds), 'DefaultTexts' => ['Товары на SANCAN']]]]]);
        return (int) ($result['AddResults'][0]['Id'] ?? throw new RuntimeException('Direct не вернул ID ShoppingAd.'));
    }

    public function updateShoppingAdProducts(int $adId, array $productIds): void
    {
        if (!$productIds) throw new RuntimeException('Пустой фильтр запрещён: сначала остановите группу.');
        // В ads.add FeedFilterConditions передаётся массивом, а ads.update
        // ожидает update-обёртку с полем Items.
        $this->call('ads', 'update', ['Ads' => [['Id' => $adId, 'ShoppingAd' => ['FeedFilterConditions' => ['Items' => $this->buildFeedFilter($productIds)], 'DefaultTexts' => ['Товары на SANCAN']]]]]);
    }

    /**
     * Submit a newly-created shopping ad for review.
     *
     * Ads.add always creates a DRAFT. Direct requires a separate Ads.moderate
     * call, so checking the status on every sync also repairs interrupted jobs.
     */
    public function submitShoppingAdForModerationIfDraft(int $adId): string
    {
        $result = $this->call('ads', 'get', [
            'SelectionCriteria' => ['Ids' => [$adId]],
            'FieldNames' => ['Id', 'Status', 'State', 'StatusClarification'],
        ]);
        $ad = $result['Ads'][0] ?? throw new RuntimeException('Direct не нашёл ShoppingAd #' . $adId . '.');
        $status = strtoupper((string) ($ad['Status'] ?? 'UNKNOWN'));

        if ($status === 'DRAFT') {
            $moderation = $this->call('ads', 'moderate', [
                'SelectionCriteria' => ['Ids' => [$adId]],
            ]);
            $action = $moderation['ModerateResults'][0] ?? [];
            if (!empty($action['Errors'])) {
                $error = $action['Errors'][0];
                throw new RuntimeException(
                    (string) ($error['Details'] ?? $error['Message'] ?? 'Не удалось отправить ShoppingAd на модерацию.'),
                    (int) ($error['Code'] ?? 0)
                );
            }
            if ((int) ($action['Id'] ?? 0) !== $adId) {
                throw new RuntimeException('Direct не подтвердил отправку ShoppingAd #' . $adId . ' на модерацию.');
            }
            return 'MODERATION';
        }

        if ($status === 'REJECTED') {
            throw new RuntimeException(
                'ShoppingAd #' . $adId . ' отклонён: ' . ((string) ($ad['StatusClarification'] ?? 'причина не указана'))
            );
        }

        return $status;
    }

    public function pauseShoppingAd(int $adId): void { $this->call('ads', 'suspend', ['SelectionCriteria' => ['Ids' => [$adId]]]); }
    public function resumeShoppingAd(int $adId): void { $this->call('ads', 'resume', ['SelectionCriteria' => ['Ids' => [$adId]]]); }

    public function applyAdGroupAdjustment(int $adGroupId,int $modifier): array
    {
        $existing=$this->call('bidmodifiers','get',['SelectionCriteria'=>['AdGroupIds'=>[$adGroupId],'Types'=>['AD_GROUP_ADJUSTMENT']],'FieldNames'=>['Id','AdGroupId','Type'],'AdGroupAdjustmentFieldNames'=>['BidModifier']]);
        $id=$existing['BidModifiers'][0]['Id']??null;
        if($id){$this->call('bidmodifiers','set',['BidModifiers'=>[['Id'=>(int)$id,'BidModifier'=>$modifier]]]);return ['id'=>(int)$id,'modifier'=>$modifier];}
        $added=$this->call('bidmodifiers','add',['BidModifiers'=>[['AdGroupId'=>$adGroupId,'AdGroupAdjustment'=>[['BidModifier'=>$modifier]]]]]);
        $result=$added['AddResults'][0]??[];
        if(!empty($result['Errors']))throw new RuntimeException((string)($result['Errors'][0]['Details']??$result['Errors'][0]['Message']??'Не удалось применить интенсивность.'));
        return ['id'=>(int)($result['Id']??0),'modifier'=>$modifier];
    }

    public function getGroupStats(array $adGroupIds, string $dateFrom, string $dateTo): array
    {
        if (!$adGroupIds) return [];
        $body = ['params'=>['SelectionCriteria'=>['Filter'=>[['Field'=>'AdGroupId','Operator'=>'IN','Values'=>array_values(array_map('strval',$adGroupIds))]],'DateFrom'=>$dateFrom,'DateTo'=>$dateTo],'FieldNames'=>['AdGroupId','Impressions','Clicks','Cost'],'ReportName'=>'SANCAN Boost '.now()->format('YmdHis'),'ReportType'=>'CUSTOM_REPORT','DateRangeType'=>'CUSTOM_DATE','Format'=>'TSV','IncludeVAT'=>'NO','IncludeDiscount'=>'NO']];
        $response = $this->client()->withHeaders(['skipReportHeader'=>'true','skipReportSummary'=>'true','returnMoneyInMicros'=>'false'])->post('https://api.direct.yandex.com/json/v5/reports',$body);
        if ($response->status() === 201 || $response->status() === 202) throw new RuntimeException('Отчёт Direct ещё формируется. Повторите синхронизацию позже.');
        $response->throw(); $lines=preg_split('/\r?\n/',trim($response->body())); $headers=str_getcsv((string)array_shift($lines),"\t"); $result=[];
        foreach($lines as $line){if(trim($line)==='')continue;$row=array_combine($headers,str_getcsv($line,"\t"));$id=(int)($row['AdGroupId']??0);if(!$id)continue;$result[$id]=['impressions'=>(int)($row['Impressions']??0),'clicks'=>(int)($row['Clicks']??0),'cost'=>(string)($row['Cost']??'0.00')];}
        return $result;
    }

    public function buildFeedFilter(array $ids): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if (!$ids) throw new RuntimeException('Пустой FeedFilterConditions запрещён.');
        sort($ids, SORT_NATURAL);
        return [['Operand' => 'id', 'Operator' => 'EQUALS_ANY', 'Arguments' => $ids]];
    }

    private function call(string $service, string $method, array $params): array
    {
        if (!$this->settings->oauth_token) throw new RuntimeException('OAuth token не настроен.');
        $response = $this->client()->post("https://api.direct.yandex.com/json/v501/{$service}", ['method' => $method, 'params' => $params]);
        $response->throw();
        $json = $response->json();
        if (isset($json['error'])) throw new RuntimeException((string) ($json['error']['error_detail'] ?? $json['error']['error_string'] ?? 'Ошибка Direct API'), (int) ($json['error']['error_code'] ?? 0));
        return $json['result'] ?? [];
    }

    private function client(): PendingRequest
    {
        $headers = ['Accept-Language' => 'ru', 'processingMode' => 'auto'];
        if ($this->settings->client_login) $headers['Client-Login'] = $this->settings->client_login;
        return Http::withToken($this->settings->oauth_token)->acceptJson()->withHeaders($headers)->timeout(25)->retry(2, 500);
    }
}
