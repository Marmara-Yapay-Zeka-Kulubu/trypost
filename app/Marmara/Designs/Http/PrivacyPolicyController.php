<?php

declare(strict_types=1);

namespace App\Marmara\Designs\Http;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The public privacy policy (/gizlilik) linked from the Google OAuth consent
 * screen. Always public and always on, also while the designs module is off.
 */
class PrivacyPolicyController extends Controller
{
    public function __invoke(): View
    {
        return view('marmara-designs::privacy');
    }
}
