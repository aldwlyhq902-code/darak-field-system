<?php

namespace App\Support;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Client choices for forms (quotations, maintenance plans) without loading
 * every client with its sites into the page.
 *
 * Up to INLINE_LIMIT active clients the select is complete, exactly as before.
 * Beyond that the form asks for a name search first and offers only the
 * matches — and says so when even the matches are capped. Nothing is ever cut
 * silently.
 */
final class ClientPicker
{
    public const INLINE_LIMIT = 200;

    /**
     * @param  array<int|string, mixed>  $with  relations the form needs per client
     * @return array{clients: Collection<int, Client>, clientSearch: string, clientSearchRequired: bool, clientResultsCapped: bool}
     */
    public static function options(Request $request, array $with = ['sites']): array
    {
        $active = Client::query()->where('is_active', true);
        $search = trim((string) $request->query('client_q', ''));
        $searchRequired = (clone $active)->count() > self::INLINE_LIMIT;

        if (! $searchRequired && $search === '') {
            return ['clients' => (clone $active)->with($with)->orderBy('name')->get(), 'clientSearch' => '', 'clientSearchRequired' => false, 'clientResultsCapped' => false];
        }
        if ($search === '') {
            return ['clients' => collect(), 'clientSearch' => '', 'clientSearchRequired' => true, 'clientResultsCapped' => false];
        }

        $clients = (clone $active)->with($with)
            ->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
            ->orderBy('name')->limit(self::INLINE_LIMIT + 1)->get();

        return [
            'clients' => $clients->take(self::INLINE_LIMIT),
            'clientSearch' => $search,
            'clientSearchRequired' => $searchRequired,
            'clientResultsCapped' => $clients->count() > self::INLINE_LIMIT,
        ];
    }
}
