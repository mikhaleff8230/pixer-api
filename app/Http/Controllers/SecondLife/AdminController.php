<?php
namespace App\Http\Controllers\SecondLife;
use App\Http\Controllers\Controller; use App\Models\{PaymentConfirmation,PaymentProfile,SecondLifeOrder}; use App\Services\Payments\DirectSbpOrderService; use Illuminate\Http\Request;
class AdminController extends Controller {
 private function admin(Request $r):void{abort_unless($r->user()->hasPermissionTo('super_admin'),403);}
 public function profiles(Request $r){$this->admin($r);return PaymentProfile::withTrashed()->with('user')->latest()->paginate($r->integer('limit',20));}
 public function orders(Request $r){$this->admin($r);return SecondLifeOrder::with(['product','buyer','seller','paymentDetails','confirmations','events'])->latest()->paginate($r->integer('limit',20));}
 public function confirmations(Request $r){$this->admin($r);return PaymentConfirmation::with(['order','buyer'])->latest()->paginate($r->integer('limit',20));}
 public function order(Request $r,string $id){$this->admin($r);return SecondLifeOrder::where('public_id',$id)->orWhere('id',$id)->with(['product','buyer','seller','paymentDetails','confirmations','events'])->firstOrFail();}
 public function action(Request $r,string $id,DirectSbpOrderService $service){$this->admin($r);$d=$r->validate(['action'=>['required','in:open_dispute,close_dispute,cancel,block_seller'],'reason'=>['nullable','string','max:2000'],'comment'=>['nullable','string','max:5000']]);$o=SecondLifeOrder::where('public_id',$id)->orWhere('id',$id)->firstOrFail();if($d['action']==='open_dispute')$o->update(['order_status'=>'disputed','payment_status'=>'disputed','dispute_reason'=>$d['reason']??'Открыт администратором']);if($d['action']==='close_dispute')$o->update(['order_status'=>'cancelled','payment_status'=>'cancelled','cancelled_at'=>now()]);if($d['action']==='cancel')$service->cancel($o);if($d['action']==='block_seller')$o->seller()->update(['is_active'=>false]);if(isset($d['comment']))$o->update(['admin_comment'=>$d['comment']]);return $o->fresh();}
}
