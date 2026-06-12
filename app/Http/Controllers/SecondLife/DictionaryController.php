<?php

namespace App\Http\Controllers\SecondLife;

use App\Http\Controllers\Controller;
use App\Models\ProductCondition;
use App\Models\ProductOriginType;
use App\Models\SellerTaxStatus;

class DictionaryController extends Controller
{
    public function taxStatuses()
    {
        return response()->json(SellerTaxStatus::orderBy('id')->get());
    }

    public function productOriginTypes()
    {
        return response()->json(ProductOriginType::orderBy('id')->get());
    }

    public function productConditions()
    {
        return response()->json(ProductCondition::orderBy('sort_order')->get());
    }
}
