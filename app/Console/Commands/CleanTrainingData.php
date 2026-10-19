<?php

namespace App\Console\Commands;

use App\Models\AiTrainingData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanTrainingData extends Command
{
    protected $signature = 'training:cleanup
        {--dry-run : Show what would be cleaned without making changes}
        {--limit=0 : Max records to process (0 = all)}';

    protected $description = 'Deactivate low-quality training data and clear their embeddings';

    protected int $flagged = 0;

    protected array $reasons = [];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $limit = (int) $this->option('limit');

        $this->info($dryRun ? '🔍 Dry-run mode — no changes will be made.' : '🧹 Cleaning low-quality training data...');

        $query = AiTrainingData::withoutGlobalScopes()
            ->where('is_active', true)
            ->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $idsToClean = [];

        $query->chunk(500, function ($records) use (&$idsToClean, $bar) {
            foreach ($records as $record) {
                $reason = $this->detectLowQuality($record);
                if ($reason) {
                    $idsToClean[] = $record->id;
                    $this->reasons[$reason] = ($this->reasons[$reason] ?? 0) + 1;
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->flagged = count($idsToClean);

        if ($this->flagged === 0) {
            $this->info('No low-quality records found.');

            return self::SUCCESS;
        }

        $this->table(['Reason', 'Count'], collect($this->reasons)->map(fn ($count, $reason) => [$reason, $count])->values()->toArray());
        $this->info("Total flagged: {$this->flagged} / {$total}");

        if ($dryRun) {
            $this->warn('Dry-run complete. Run without --dry-run to apply changes.');

            return self::SUCCESS;
        }

        // Apply: set is_active=false, embedding=null in chunks
        $chunks = array_chunk($idsToClean, 500);
        foreach ($chunks as $chunk) {
            DB::table('ai_training_data')
                ->whereIn('id', $chunk)
                ->update(['is_active' => false, 'embedding' => null]);
        }

        $this->info("Deactivated {$this->flagged} records.");

        return self::SUCCESS;
    }

    protected function detectLowQuality(AiTrainingData $record): ?string
    {
        $question = trim($record->question ?? '');
        $answer = trim($record->answer ?? '');

        // Strip product name prefix (often prepended as "ÜRÜN ADI - soru")
        $cleanQuestion = preg_replace('/^[^?:]+\s*[-–—:]\s*/u', '', $question);
        $cleanQuestion = trim($cleanQuestion);

        // Rule 1: Very short question (< 5 chars after prefix removal)
        if (mb_strlen($cleanQuestion) < 5) {
            return 'Çok kısa soru (< 5 karakter)';
        }

        // Rule 2: Empty or very short answer (< 3 chars)
        if (mb_strlen($answer) < 3) {
            return 'Boş/çok kısa cevap (< 3 karakter)';
        }

        // Rule 3: Meaningless content (only punctuation, emojis, single word)
        if (preg_match('/^[\p{P}\p{S}\s]+$/u', $answer)) {
            return 'Anlamsız içerik (sadece noktalama/emoji)';
        }

        if (str_word_count($answer) <= 1 && mb_strlen($answer) < 20) {
            return 'Anlamsız içerik (tek kelime cevap)';
        }

        // Rule 4: Generic dismissive answers — only flag if the ENTIRE answer is a greeting/dismissal
        $lowerAnswer = mb_strtolower($answer);
        // Remove "merhaba", "merhaba efendim", commas, dots from start for content check
        $contentOnly = preg_replace('/^(merhaba\s*(efendim)?[,.\s]*)/u', '', $lowerAnswer);
        $contentOnly = trim($contentOnly, " \t\n\r\0\x0B.,;");

        $dismissivePatterns = [
            'nasıl yardımcı olabiliriz',
            'nasıl yardımcı olabilirim',
            'yardımcı olabilir miyiz',
            'size nasıl yardımcı',
            'iyi günler dileriz',
            'iyi günler',
            'hayırlı günler',
            'kolay gelsin',
            'hoş geldiniz',
        ];

        if (mb_strlen($contentOnly) < 30) {
            foreach ($dismissivePatterns as $pattern) {
                if (str_contains($contentOnly, $pattern)) {
                    return 'Genel savuşturma cevabı';
                }
            }
            // Pure greetings with no content
            if ($contentOnly === '' && mb_strlen($answer) < 30) {
                return 'Genel savuşturma cevabı';
            }
        }

        // Rule 5: Question = Answer (copy-paste artifact)
        if (mb_strtolower($question) === mb_strtolower($answer)) {
            return 'Soru = Cevap (copy-paste)';
        }

        return null;
    }
}
