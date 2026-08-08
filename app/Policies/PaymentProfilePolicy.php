<?php
namespace App\Policies; use App\Models\PaymentProfile; use Marvel\Database\Models\User;
class PaymentProfilePolicy { public function view(User $u,PaymentProfile $p):bool{return (int)$u->id===(int)$p->user_id||$u->hasPermissionTo('super_admin');} public function update(User $u,PaymentProfile $p):bool{return $this->view($u,$p);} public function delete(User $u,PaymentProfile $p):bool{return $this->view($u,$p);} }
