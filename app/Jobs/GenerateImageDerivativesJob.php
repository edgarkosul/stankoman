<?php

namespace App\Jobs;

use App\Support\ImageDerivativesGenerator;
use App\Support\ImageDerivativesResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateImageDerivativesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public string $sourcePath,
        public bool $force = false,
    ) {}

    public function uniqueId(): string
    {
        $resolver = app(ImageDerivativesResolver::class);

        return $resolver->hashKeyFromSourcePath($this->sourcePath);
    }

    public function handle(ImageDerivativesGenerator $generator): void
    {
        $result = $generator->generateFromSourcePath($this->sourcePath, $this->force);

        Log::info('Image derivatives generated', [
            'sourcePath' => $result->sourcePath,
            'key' => $result->key,
            'generated' => $result->generatedWidths,
            'skipped' => $result->skipped,
            'status' => $result->status,
        ]);
    }
}
