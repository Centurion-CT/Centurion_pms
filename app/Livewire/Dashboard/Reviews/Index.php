<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard\Reviews;

use App\Models\Review;
use App\Models\Room;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Operator-facing list of all guest reviews (PRD §6.4.1 + per-room
 * extension). Property-wide reviews and per-room reviews live in the
 * same table; the filter lets the operator narrow to "property only"
 * or a specific room.
 */
#[Layout('layouts.tenant-auth')]
class Index extends Component
{
    use WithPagination;

    /** 'all' | 'property' | room id as string */
    #[Url(as: 'scope')]
    public string $scopeFilter = 'all';

    #[Url(as: 'hidden')]
    public bool $includeHidden = true;

    public function updatedScopeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedIncludeHidden(): void
    {
        $this->resetPage();
    }

    public function toggleDisplayed(int $reviewId): void
    {
        $review = Review::query()->find($reviewId);
        if ($review === null) {
            return;
        }
        $review->forceFill(['is_displayed' => ! $review->is_displayed])->save();
    }

    public function delete(int $reviewId): void
    {
        $review = Review::query()->find($reviewId);
        $review?->delete();
        session()->flash('reviews-status', __('Review deleted.'));
    }

    public function render(): View
    {
        return view('livewire.dashboard.reviews.index', array_merge([
            'reviews' => $this->paginate(),
            'rooms' => Room::query()->orderByDisplayName()->get(),
        ], $this->aggregateStats()));
    }

    /**
     * Aggregate rating card + monthly trend sparkline + top-mentioned
     * keyword cloud. Cheap (aggregate SQL + a single body scan) so we
     * happily recompute on every render instead of caching.
     *
     * @return array{
     *   avgRating: float|null,
     *   reviewCount: int,
     *   responseCount: int,
     *   ratingHistogram: array<int, int>,
     *   monthlyTrend: array<int, array{month: string, avg: float, count: int}>,
     *   topKeywords: array<int, array{term: string, count: int}>,
     * }
     */
    private function aggregateStats(): array
    {
        // Aggregate always counts DISPLAYED reviews; a hidden review has
        // no bearing on how a guest perceives the property, so it should
        // not sway the operator's "am I trending up?" gut read.
        $base = Review::query()->where('is_displayed', true);
        $count = (clone $base)->count();
        $avg = $count > 0 ? (float) (clone $base)->avg('rating') : null;
        $responseCount = (clone $base)->whereNotNull('response_body')->count();

        // Rating histogram — 5 buckets, used to draw a small bar strip.
        $histogramRaw = (clone $base)
            ->selectRaw('rating, COUNT(*) as c')
            ->groupBy('rating')
            ->pluck('c', 'rating')
            ->all();
        $histogram = [];
        for ($star = 5; $star >= 1; $star--) {
            $histogram[$star] = (int) ($histogramRaw[$star] ?? 0);
        }

        // Monthly trend for the last 12 months. Even for properties with
        // sparse reviews, this makes cadence visible + gives a place to
        // hang "your review volume is picking up" copy in later slices.
        //
        // Aggregating in PHP instead of SQL keeps the query portable
        // across the SQLite (dev + tests) and MySQL (prod) grammars -
        // strftime is SQLite-only and would 500 on prod. A property's
        // review count is small enough that a 12-month window fetch is
        // trivial (dozens of rows at most).
        $sinceMonth = Carbon::now()->startOfMonth()->subMonths(11);
        $rows = (clone $base)
            ->where('created_at', '>=', $sinceMonth)
            ->get(['rating', 'created_at']);
        $byMonth = [];
        foreach ($rows as $row) {
            /** @var Carbon $when */
            $when = $row->created_at instanceof Carbon
                ? $row->created_at
                : Carbon::parse((string) $row->created_at);
            $ym = $when->format('Y-m');
            if (! isset($byMonth[$ym])) {
                $byMonth[$ym] = ['sum' => 0, 'count' => 0];
            }
            $byMonth[$ym]['sum'] += (int) $row->rating;
            $byMonth[$ym]['count'] += 1;
        }
        foreach ($byMonth as $ym => &$bucket) {
            $bucket['avg'] = $bucket['count'] > 0
                ? $bucket['sum'] / $bucket['count']
                : 0.0;
        }
        unset($bucket);
        // Fill missing months so the sparkline reads as a continuous line.
        $monthlyTrend = [];
        for ($m = 0; $m < 12; $m++) {
            $when = $sinceMonth->copy()->addMonths($m);
            $key = $when->format('Y-m');
            $monthlyTrend[] = [
                'month' => $when->format('M'),
                'avg' => $byMonth[$key]['avg'] ?? 0.0,
                'count' => $byMonth[$key]['count'] ?? 0,
            ];
        }

        return [
            'avgRating' => $avg,
            'reviewCount' => $count,
            'responseCount' => $responseCount,
            'ratingHistogram' => $histogram,
            'monthlyTrend' => $monthlyTrend,
            'topKeywords' => $this->extractKeywords((clone $base)->pluck('body')->all()),
        ];
    }

    /**
     * Simple noun-phrase cloud without an NLP dep. Splits every review
     * body on non-word chars, drops a static stopword list, keeps 3+ char
     * terms only, ranks by frequency, returns the top 12. Not linguistics,
     * but it surfaces the words guests actually type ("wifi", "breakfast",
     * "quiet", "power") which is what an operator wants to see.
     *
     * @param  array<int, string>  $bodies
     * @return array<int, array{term: string, count: int}>
     */
    private function extractKeywords(array $bodies): array
    {
        $stopwords = [
            'the', 'and', 'was', 'for', 'this', 'that', 'with', 'have', 'were', 'are',
            'from', 'had', 'has', 'not', 'but', 'you', 'they', 'them', 'their', 'our',
            'all', 'any', 'can', 'could', 'would', 'will', 'been', 'more', 'much',
            'very', 'just', 'about', 'when', 'what', 'why', 'how', 'here', 'there',
            'stay', 'stayed', 'staying', 'room', 'rooms', 'hotel', 'place', 'get',
            'got', 'one', 'two', 'first', 'next', 'time', 'day', 'days', 'night',
            'nights', 'like', 'nice', 'good', 'great', 'really', 'also', 'even',
            'still', 'made', 'make', 'sure', 'came', 'went', 'back', 'over', 'into',
            'thanks', 'thank', 'again', 'ever', 'his', 'her', 'him', 'she', 'she',
        ];
        $stopMap = array_fill_keys($stopwords, true);

        // Static polarity lexicons. These are the words guests actually
        // type at Nigerian boutique properties, curated by hand from the
        // seeded reviews + common hospitality feedback. Not a sentiment
        // model — deliberately so, since a two-list heuristic keeps the
        // cloud transparent to the operator: they can guess which bucket
        // a term will land in without training data or an API call.
        $positive = array_fill_keys([
            'quiet', 'clean', 'spotless', 'perfect', 'comfortable', 'friendly',
            'helpful', 'wonderful', 'love', 'loved', 'beautiful', 'excellent',
            'cozy', 'peaceful', 'secure', 'fast', 'reliable', 'delicious',
            'warm', 'welcoming', 'gracious', 'attentive', 'stunning', 'bright',
            'fresh', 'thoughtful', 'genuine', 'lovely', 'brilliant', 'flawless',
            'seamless', 'exceptional', 'attention', 'view', 'balcony',
            'breakfast', 'coffee', 'wifi', 'internet', 'power', 'aircon',
            'kitchenette', 'polite', 'punctual', 'responsive', 'organised',
        ], true);
        $negative = array_fill_keys([
            'dirty', 'broken', 'noisy', 'rude', 'slow', 'stained', 'disappointed',
            'terrible', 'awful', 'poor', 'issue', 'issues', 'cold', 'hot',
            'moldy', 'stink', 'smelly', 'cramped', 'dark', 'unsafe',
            'delayed', 'rushed', 'overcharged', 'overpriced', 'overpricing',
            'unreliable', 'flaky', 'unresponsive', 'confusing', 'confused',
            'leak', 'leaked', 'leaking', 'stopped', 'stuck', 'lost', 'missing',
            'complaint', 'complained', 'unhappy', 'frustrated', 'annoyed',
        ], true);

        $counts = [];
        $polarityHint = [];
        foreach ($bodies as $body) {
            $normalised = mb_strtolower((string) $body);
            $tokens = preg_split('/[^\p{L}\d]+/u', $normalised, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $token) {
                $token = trim($token);
                if (mb_strlen($token) < 4) continue;
                if (isset($stopMap[$token])) continue;
                $counts[$token] = ($counts[$token] ?? 0) + 1;
                if (isset($positive[$token])) {
                    $polarityHint[$token] = 'positive';
                } elseif (isset($negative[$token])) {
                    $polarityHint[$token] = 'negative';
                }
            }
        }
        // Only surface words that show up at least twice — a single
        // one-off word ("actinolite") isn't a signal, it's noise.
        $counts = array_filter($counts, fn ($c) => $c >= 2);
        arsort($counts, SORT_NUMERIC);
        $top = array_slice($counts, 0, 14, true);

        $out = [];
        foreach ($top as $term => $count) {
            $out[] = [
                'term'     => $term,
                'count'    => $count,
                'polarity' => $polarityHint[$term] ?? 'neutral',
            ];
        }
        return $out;
    }

    /**
     * @return LengthAwarePaginator<int, Review>
     */
    private function paginate(): LengthAwarePaginator
    {
        $query = Review::query()->with('room')->latest('id');

        if ($this->scopeFilter === 'property') {
            $query->whereNull('room_id');
        } elseif (is_numeric($this->scopeFilter) && (int) $this->scopeFilter > 0) {
            $query->where('room_id', (int) $this->scopeFilter);
        }

        if (! $this->includeHidden) {
            $query->where('is_displayed', true);
        }

        return $query->paginate(20);
    }
}
