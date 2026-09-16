<?php

namespace App\Console\Commands;

use App\Models\SurveyResponse;
use App\Support\Surveys\BotKnowledgeQuestionnaire;
use Illuminate\Console\Command;

/**
 * Ответы владельца на «Вопросы для бота» — текстом, по которому пишутся
 * статьи базы знаний. Отвечает владелец на бою, поэтому забирать так:
 *
 *   ssh intertooler-production 'php artisan survey:bot-knowledge' > answers.md
 *
 * Под каждым ответом — фраза, которую владелец видел в превью чата. Это
 * черновик реплики бота, а не готовая статья: её ещё вычитывает владелец.
 */
class SurveyBotKnowledgeCommand extends Command
{
    protected $signature = 'survey:bot-knowledge
        {--draft : Черновик — то, что владелец успел заполнить, но, может быть, не отправил}';

    protected $description = 'Показать ответы владельца на вопросы для ИИ-бота';

    public function handle(): int
    {
        $draft = (bool) $this->option('draft');

        $response = SurveyResponse::latestFor($draft
            ? SurveyResponse::SURVEY_BOT_KNOWLEDGE_DRAFT
            : SurveyResponse::SURVEY_BOT_KNOWLEDGE);

        if ($response === null) {
            $this->warn($draft
                ? 'Черновика нет: анкету ещё не открывали.'
                : 'Отправленных ответов нет. Посмотреть недописанное: --draft');

            return self::SUCCESS;
        }

        $answers = (array) $response->answers;
        $at = ($draft ? $response->updated_at : $response->created_at)?->timezone('Europe/Moscow')->format('d.m.Y H:i');
        $who = $response->user?->email ?? 'неизвестно кто';

        $this->line('# Вопросы для бота — '.($draft ? 'черновик' : 'ответы'));
        $this->line('');
        $this->line(($draft ? 'Сохранено' : 'Отправлено').": {$at}, {$who}. Отвечено "
            .BotKnowledgeQuestionnaire::answeredCount($answers).' из '.count(BotKnowledgeQuestionnaire::questions()).'.');
        $this->line('');
        $this->line(BotKnowledgeQuestionnaire::toMarkdown($answers));

        return self::SUCCESS;
    }
}
