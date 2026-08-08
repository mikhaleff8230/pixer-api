<?php
namespace App\Services\Payments;
use App\Models\PaymentProfile;
use App\Models\SecondLifeOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Marvel\Database\Models\User;
class PaymentProfileService {
 public function createProfile(User $seller,array $data):PaymentProfile { return DB::transaction(function()use($seller,$data){$data['user_id']=$seller->id;$data['type']='person_sbp';if(!empty($data['is_default']))$this->clear($seller);$p=PaymentProfile::create($data);if(!PaymentProfile::where('user_id',$seller->id)->where('is_default',true)->exists())$p->update(['is_default'=>true]);return $p->fresh();}); }
 public function updateProfile(User $seller,PaymentProfile $profile,array $data):PaymentProfile {$this->owner($seller,$profile);return DB::transaction(function()use($seller,$profile,$data){if(!empty($data['is_default']))$this->clear($seller);if(($data['is_active']??true)===false&&$profile->is_default)throw ValidationException::withMessages(['is_active'=>'Основной профиль нельзя отключить']);$profile->update($data);return $profile->fresh();});}
 public function setDefault(User $seller,PaymentProfile $profile):void {$this->owner($seller,$profile);if(!$profile->is_active)throw ValidationException::withMessages(['profile'=>'Профиль неактивен']);DB::transaction(function()use($seller,$profile){$this->clear($seller);$profile->update(['is_default'=>true]);});}
 public function activate(User $s,PaymentProfile $p):void{$this->updateProfile($s,$p,['is_active'=>true]);} public function deactivate(User $s,PaymentProfile $p):void{$this->updateProfile($s,$p,['is_active'=>false]);}
 public function deleteProfile(User $s,PaymentProfile $p):void{$this->owner($s,$p);if(SecondLifeOrder::where('payment_profile_id',$p->id)->whereNotIn('order_status',['completed','cancelled'])->exists())throw ValidationException::withMessages(['profile'=>'Профиль используется в активном заказе']);$p->delete();}
 public function getDefaultProfile(User $s):?PaymentProfile{return PaymentProfile::where('user_id',$s->id)->active()->default()->first();}
 private function clear(User $s):void{PaymentProfile::where('user_id',$s->id)->update(['is_default'=>false]);} private function owner(User $s,PaymentProfile $p):void{abort_unless((int)$s->id===(int)$p->user_id,403);}
}
