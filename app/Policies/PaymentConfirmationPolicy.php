<?php
namespace App\Policies; use App\Models\PaymentConfirmation; use Marvel\Database\Models\User;
class PaymentConfirmationPolicy { public function view(User $u,PaymentConfirmation $c):bool{return in_array((int)$u->id,[(int)$c->buyer_id,(int)$c->order->seller_id],true)||$u->hasPermissionTo('super_admin');} }
