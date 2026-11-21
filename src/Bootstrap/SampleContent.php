<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

/**
 * Sample weavers, journal articles and bank-transfer accounts seeded on a
 * fresh install (N7, PRD §8, §10). Every weaver is fictional: no real
 * person's name, photo or likeness. Weavers cannot sign in until an
 * administrator sets credentials (their password hash is unusable).
 *
 * Placeholder copy (M9): stories, article bodies and sources still need the
 * content review tracked in BUILD M9 before they are presented as research.
 */
final class SampleContent
{
    /**
     * @return list<array{slug: string, email: string, display_name: string, community: string,
     *     region: string, fibers: string, techniques: string, story: string}>
     */
    public static function weavers(): array
    {
        return [
            [
                'slug' => 'nena-quezon',
                'email' => 'nena-quezon@weavers.invalid',
                'display_name' => 'Nena',
                'community' => 'Quezon',
                'region' => 'CALABARZON (Region IV-A)',
                'fibers' => 'buri',
                'techniques' => 'Checker plaiting',
                'story' => 'Nena plaits buri strips into market totes.',
            ],
            [
                'slug' => 'rosa-basey',
                'email' => 'rosa-basey@weavers.invalid',
                'display_name' => 'Rosa',
                'community' => 'Basey, Samar',
                'region' => 'Eastern Visayas (Region VIII)',
                'fibers' => 'tikog',
                'techniques' => 'Banig plaiting',
                'story' => 'Rosa plaits dyed tikog grass into mats and structured bags.',
            ],
            [
                'slug' => 'lorna-catanduanes',
                'email' => 'lorna-catanduanes@weavers.invalid',
                'display_name' => 'Lorna',
                'community' => 'Catanduanes',
                'region' => 'Bicol Region (Region V)',
                'fibers' => 'abaca',
                'techniques' => 'Striped loom weaving',
                'story' => 'Lorna weaves abaca fiber into striped cloth and slings.',
            ],
            [
                'slug' => 'carmen-bohol',
                'email' => 'carmen-bohol@weavers.invalid',
                'display_name' => 'Carmen',
                'community' => 'Bohol',
                'region' => 'Central Visayas (Region VII)',
                'fibers' => 'pandan',
                'techniques' => 'Pattern plaiting',
                'story' => 'Carmen plaits pandan leaves into patterned clutches.',
            ],
        ];
    }

    /**
     * @return list<array{slug: string, title: string, excerpt: string, body: string, sources: string}>
     */
    public static function articles(): array
    {
        return [
            [
                'slug' => 'history-of-the-bayong',
                'title' => 'A short history of the bayong',
                'excerpt' => 'How the woven market bag became an everyday Filipino carryall.',
                'body' => 'The bayong is a woven bag carried to markets across the Philippines.',
                'sources' => '',
            ],
            [
                'slug' => 'native-fibers',
                'title' => 'Buri, pandan, tikog and abaca',
                'excerpt' => 'The four native fibers behind the Habi collection.',
                'body' => 'Each Habi bag is woven from one native fiber: buri, pandan, tikog or abaca.',
                'sources' => '',
            ],
            [
                'slug' => 'weaving-traditions',
                'title' => 'Weaving traditions of the islands',
                'excerpt' => 'Plaiting and loom weaving in the communities Habi works with.',
                'body' => 'Philippine weaving includes plaited mats and bags as well as loom-woven cloth.',
                'sources' => '',
            ],
        ];
    }

    /**
     * Bank-transfer and e-wallet accounts shown at checkout (M11, R13).
     * Fictional: the institutions and numbers do not exist.
     *
     * @return list<array{method: string, provider: string, account_name: string, account_number: string}>
     */
    public static function transferAccounts(): array
    {
        return [
            [
                'method' => 'bank',
                'provider' => 'Banco Habi',
                'account_name' => 'Habi Crafts',
                'account_number' => '0000-0000-0000',
            ],
            [
                'method' => 'ewallet',
                'provider' => 'HabiPay',
                'account_name' => 'Habi Crafts',
                'account_number' => '0900 000 0000',
            ],
        ];
    }
}
