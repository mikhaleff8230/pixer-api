<?php
namespace App\Policies; use App\Models\SecondLifeOrder; use Marvel\Database\Models\User;
class OrderPolicy { public function view(User $u,SecondLifeOrder $o):bool{return in_array((int)$u->id,[(int)$o->buyer_id,(int)$o->seller_id],true)||$u->hasPermissionTo('super_admin');} public function markPaid(User $u,SecondLifeOrder $o):bool{return (int)$u->id===(int)$o->buyer_id&&$o->payment_status==='waiting_payment';} public function confirmPayment(User $u,SecondLifeOrder $o):bool{return (int)$u->id===(int)$o->seller_id&&$o->payment_status==='buyer_marked_paid';} }
