<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up():void{Schema::table('yandex_direct_settings',function(Blueprint $table){$table->decimal('desired_bid_ceiling',8,2)->default(40);$table->decimal('applied_bid_ceiling',8,2)->nullable();$table->string('bid_ceiling_sync_status')->default('pending');});}
 public function down():void{Schema::table('yandex_direct_settings',fn(Blueprint $table)=>$table->dropColumn(['desired_bid_ceiling','applied_bid_ceiling','bid_ceiling_sync_status']));}
};
