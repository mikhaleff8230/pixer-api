<?php

namespace Tests\Feature;

use App\Exceptions\YandexDirectReportPending;
use App\Models\YandexDirectSetting;
use App\Services\YandexDirect\YandexDirectService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YandexDirectReportTest extends TestCase
{
    public function test_offline_report_is_polled_with_the_same_request_until_ready(): void
    {
        Http::fakeSequence()
            ->push('', 201, ['retryIn' => '0'])
            ->push('', 202, ['retryIn' => '0'])
            ->push("AdGroupId\tImpressions\tClicks\tCost\n123\t40\t3\t12.50\n", 200);

        $result = $this->service()->getGroupStats([123], '2026-09-01', '2026-09-26');

        $this->assertSame(['impressions'=>40,'clicks'=>3,'cost'=>'12.50'], $result[123]);
        $reportNames=[];
        Http::assertSent(function(Request $request)use(&$reportNames){
            $reportNames[]=$request->data()['params']['ReportName'];
            return $request->url()==='https://api.direct.yandex.com/json/v501/reports';
        });
        $this->assertCount(3,$reportNames);
        $this->assertCount(1,array_unique($reportNames));
    }

    public function test_separate_reports_get_unique_names(): void
    {
        Http::fake(['*'=>Http::response("AdGroupId\tImpressions\tClicks\tCost\n",200)]);

        $service=$this->service();
        $service->getGroupStats([123],'2026-09-01','2026-09-26');
        $service->getGroupStats([123],'2026-09-26','2026-09-26');

        $reportNames=[];
        Http::assertSent(function(Request $request)use(&$reportNames){$reportNames[]=$request->data()['params']['ReportName'];return true;});
        $this->assertCount(2,array_unique($reportNames));
    }

    public function test_pending_report_is_a_transient_state_after_bounded_retries(): void
    {
        Http::fake(['*'=>Http::response('',202,['retryIn'=>'0'])]);

        $this->expectException(YandexDirectReportPending::class);
        $this->service()->getGroupStats([123],'2026-09-01','2026-09-26');
    }

    private function service(): YandexDirectService
    {
        $settings=new class extends YandexDirectSetting {
            public function getAttribute($key)
            {
                return match($key){
                    'oauth_token'=>'test-token',
                    'client_login'=>null,
                    default=>parent::getAttribute($key),
                };
            }
        };
        $settings->exists=true;
        return new YandexDirectService($settings);
    }
}
