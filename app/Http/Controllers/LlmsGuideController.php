<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Site\BookingSite;
use Illuminate\Http\Response;

/**
 * /llms.txt - the emerging convention (llmstxt.org) for giving
 * LLM crawlers (ChatGPT, Claude, Perplexity, and RAG scrapers)
 * a curated, factual overview of a site.
 *
 * Served on each business's address, so it describes that business's
 * booking site: its buildings and the pages to book and manage a stay
 * (docs/MULTITENANCY.md, step 6). Built only from the business's own
 * data; nothing is claimed that the business hasn't entered.
 */
class LlmsGuideController
{
    public function __invoke(BookingSite $site): Response
    {
        $business = $site->business();
        $base = url('/');
        $branches = $site->branches();

        $lines = ['# '.$business->siteTitle(), ''];
        $lines[] = '> '.$business->name.' takes bookings online on this site: live availability'
            .($branches->count() > 1 ? ' across '.$branches->count().' buildings' : '')
            .($site->city() ? ' in '.$site->city() : '').', paid by card or bank transfer through Paystack.';
        $lines[] = '';
        $lines[] = '## Book';
        $lines[] = "- [Home]({$base}/): search dates and see the buildings on a map";
        $lines[] = "- [Apartments]({$base}/apartments): every apartment free for a stay, with prices";
        $lines[] = "- [Manage a booking]({$base}/manage): find a booking by its reference and the guest's email or phone";

        if ($branches->isNotEmpty()) {
            $lines[] = '';
            $lines[] = '## Buildings';
            foreach ($branches as $branch) {
                $where = trim(implode(', ', array_filter([$branch->address, $branch->city])));
                $lines[] = "- [{$branch->name}]({$base}/apartments?where[]={$branch->slug})".($where !== '' ? ": {$where}" : '');
            }
        }

        $contact = array_filter([$business->phone, $business->email]);
        if ($contact !== []) {
            $lines[] = '';
            $lines[] = '## Contact';
            $lines[] = '- '.implode(' · ', $contact);
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
