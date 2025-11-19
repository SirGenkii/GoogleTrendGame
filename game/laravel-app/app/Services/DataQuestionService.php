<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DataQuestionService
{
    public function fetchQuestion(?string $theme = null, ?int $year = null, ?string $semester = null): ?array
    {
        $query = DB::connection('data')
            ->table('questions')
            ->join('themes', 'questions.theme_id', '=', 'themes.id')
            ->join('question_articles', 'questions.id', '=', 'question_articles.question_id')
            ->where('questions.status', 'ready')
            ->groupBy('questions.id', 'themes.name', 'questions.year', 'questions.semester')
            ->havingRaw('COUNT(question_articles.id) = 4');

        if ($theme) {
            $query->where('themes.name', $theme);
        }
        if ($year) {
            $query->where('questions.year', $year);
        }
        if ($semester) {
            $query->where('questions.semester', $semester);
        }

        $question = $query->inRandomOrder()->first([
            'questions.id as id',
            'themes.name as theme',
            'questions.year',
            'questions.semester',
        ]);

        if (!$question) {
            return null;
        }

        $articles = DB::connection('data')
            ->table('question_articles')
            ->join('articles', 'question_articles.article_id', '=', 'articles.id')
            ->where('question_articles.question_id', $question->id)
            ->get([
                'articles.title',
                'articles.summary',
                'articles.image_url',
                'question_articles.views_total',
                'question_articles.views_avg_daily',
            ]);

        return [
            'id' => $question->id,
            'theme' => $question->theme,
            'year' => $question->year,
            'semester' => $question->semester,
            'articles' => $articles,
        ];
    }

    public function orderAscendingByPopularity(Collection $articles): array
    {
        return $articles
            ->sortBy('views_avg_daily')
            ->pluck('title')
            ->values()
            ->all();
    }
}
