<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Lib\Config;

/** Static informational pages. */
final class PageController extends Controller
{
    public function about(): void
    {
        $this->view('public/about', ['title' => 'About']);
    }

    public function faq(): void
    {
        $this->view('public/faq', [
            'title' => 'FAQ',
            'requiredConfirmations' => Config::int('payments.required_confs', 2),
        ]);
    }

    public function terms(): void
    {
        $this->view('public/terms', ['title' => 'Terms of sale']);
    }

    public function privacy(): void
    {
        $this->view('public/privacy', ['title' => 'Privacy']);
    }
}
