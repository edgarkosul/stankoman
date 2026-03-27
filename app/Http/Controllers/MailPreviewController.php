<?php

namespace App\Http\Controllers;

use App\Support\Mail\MailPreviewFactory;
use InvalidArgumentException;

class MailPreviewController extends Controller
{
    public function __construct(private readonly MailPreviewFactory $previews) {}

    public function index()
    {
        return view('dev.mail-previews.index', [
            'groups' => $this->previews->groupedCatalog(),
        ]);
    }

    public function show(string $preview)
    {
        try {
            $html = $this->previews->render($preview);
        } catch (InvalidArgumentException) {
            abort(404);
        }

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
