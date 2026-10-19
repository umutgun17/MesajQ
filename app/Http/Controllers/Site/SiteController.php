<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Contracts\View\View;

/** Tanıtım sitesi: ana sayfa, fiyatlandırma, yasal metinler, iletişim. */
class SiteController extends Controller
{
    /** SITE_MAINTENANCE=true iken tanıtım sayfaları "yakında" ekranı gösterir; panel ve API etkilenmez. */
    public function __construct()
    {
        if ((bool) config('saas.site_maintenance')) {
            abort(response()->view('site.maintenance', [], 503)->header('Retry-After', '3600'));
        }
    }

    public function home(): View
    {
        return view('site.home', ['landing' => config('landing'), 'plans' => $this->plans()]);
    }

    public function pricing(): View
    {
        return view('site.pricing', ['landing' => config('landing'), 'plans' => $this->plans()]);
    }

    public function legal(string $page): View
    {
        abort_unless(in_array($page, ['kvkk', 'gizlilik', 'kullanim-sartlari', 'mesafeli-satis'], true), 404);

        return view('site.legal', ['page' => $page, 'landing' => config('landing')]);
    }

    public function contact(): View
    {
        return view('site.contact', ['landing' => config('landing')]);
    }

    private function plans()
    {
        try {
            return Plan::where('family', config('saas.family'))->where('is_public', true)->orderBy('sort_order')->get();
        } catch (\Throwable) {
            return collect();
        }
    }
}
