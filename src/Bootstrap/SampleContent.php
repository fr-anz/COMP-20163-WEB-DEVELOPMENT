<?php

declare(strict_types=1);

namespace Habi\Bootstrap;

/**
 * Weavers, journal articles and bank-transfer accounts seeded on a fresh
 * install (N7, PRD §2, §8, §10). Every weaver is fictional: an invented first
 * name, no real person's name, photo or likeness. Weavers cannot sign in until
 * an administrator sets credentials (their password hash is unusable).
 *
 * Stories and article bodies describe real craft traditions and are written
 * from the secondary sources listed in each article's `sources` (BUILD M9).
 * Content format shared with the storefront and admin: stories and bodies are
 * plain text, paragraphs separated by one blank line, no HTML or Markdown;
 * `sources` holds one source per line as
 * `Author or Organisation. Title. Publisher, Year. URL` (n.d. when undated).
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
                'community' => 'Sampaloc, Quezon',
                'region' => 'CALABARZON (Region IV-A)',
                'fibers' => 'buri',
                'techniques' => 'Checker plaiting',
                'story' => self::prose(<<<'TXT'
                    Nena weaves buri in Sampaloc, Quezon, a hill town between Lucban and Mauban
                    where buri, called buli locally, has long been woven into baskets, hats, bags,
                    wallets and tampipi cases.

                    Most of her work happens before the first row. The leaves are harvested,
                    stripped, dried in the sun, boiled, dried again and flattened, then cut into
                    even strips. Colouring takes about half an hour, and a leaf is usually ready
                    to weave within a week of harvest.

                    Nena plaits in a close checker, crossing strips over and under at right angles
                    until the body of a tote holds its own shape. A handbag is a full day of
                    steady paglala.
                    TXT),
            ],
            [
                'slug' => 'rosa-basey',
                'email' => 'rosa-basey@weavers.invalid',
                'display_name' => 'Rosa',
                'community' => 'Basey, Samar',
                'region' => 'Eastern Visayas (Region VIII)',
                'fibers' => 'tikog',
                'techniques' => 'Banig plaiting',
                'story' => self::prose(<<<'TXT'
                    Rosa plaits tikog in Basey, Samar, the town known as the banig capital of the
                    Philippines, where women have long woven this native sedge into sleeping mats.

                    Tikog in Basey is grown in the town's own fields and pulled stalk by stalk
                    rather than cut. The stalks are dried in the sun, boiled in dye, dried again
                    and pressed flat before they are fine enough to plait.

                    Rosa works in the banig tradition, building colour into the plait itself. The
                    same skill that patterns a mat gives a satchel its firm, even walls.
                    TXT),
            ],
            [
                'slug' => 'lorna-catanduanes',
                'email' => 'lorna-catanduanes@weavers.invalid',
                'display_name' => 'Lorna',
                'community' => 'Catanduanes',
                'region' => 'Bicol Region (Region V)',
                'fibers' => 'abaca',
                'techniques' => 'Handloom weaving',
                'story' => self::prose(<<<'TXT'
                    Lorna weaves abaca on Catanduanes, the island province declared by law the
                    Abaca Capital of the Philippines as the country's biggest producer of the
                    fiber.

                    Abaca fiber comes from the outer layer of the plant's leafsheath, which is
                    pulled under a knife to strip the fiber clean and then dried. Good fiber is
                    long and strong, which suits it to the loom.

                    Lorna weaves on a handloom, the craft now taught in the province's new abaca
                    weaving workshops, setting stripes of colour into the cloth as it grows.
                    TXT),
            ],
            [
                'slug' => 'carmen-bohol',
                'email' => 'carmen-bohol@weavers.invalid',
                'display_name' => 'Carmen',
                'community' => 'Luisiana, Laguna',
                'region' => 'CALABARZON (Region IV-A)',
                'fibers' => 'pandan',
                'techniques' => 'Pattern plaiting',
                'story' => self::prose(<<<'TXT'
                    Carmen plaits pandan in Luisiana, Laguna, a town where sun-drying pandan
                    leaves are a common sight in the streets and where weavers make bayong,
                    banig mats and bags at home.

                    The pandan of Luisiana is Pandanus simplex. Its leaves are stripped by hand and
                    dried in the sun before they are plaited, slow preparation that decides how
                    fine the finished weave can be.

                    Carmen works small, patterned pieces, plaiting coloured strips into a motif
                    as she goes so that the design is part of the weave rather than painted on.
                    TXT),
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
                'excerpt' => 'How a plain woven market bag of buri and pandan leaf became an everyday Filipino '
                    . 'carryall.',
                'body' => self::prose(<<<'TXT'
                    For many Filipinos the word bayong still calls up a trip to the wet market:
                    haggling over produce, fish and meat, then walking home with a woven bag
                    holding the week's food. The traditional bayong is woven from buri leaves in
                    the Visayas and from pandan in Luzon, according to the Product Development and
                    Design Center of the Philippines.

                    The bayong belongs to a much wider tradition of Philippine woven carriers. The
                    National Museum of the Philippines describes baskets as containers for
                    harvests, food and personal belongings, used to bring produce from the field to
                    the house or the market on the head, back, hands or shoulders. Among the
                    Hanunuo Mangyan, the bay-ong sinuluyan is a small traditional bag of soft,
                    narrow buri strips, carried by men and women alike to hold betel chew
                    ingredients and other small things. Its pakudos, a cross pattern of split nito
                    laid over the buri, is a traditional symbol believed to protect the bearer.

                    Function comes first. A 2009 bayong exhibit at the design center
                    opened with a sign saying the bag "is valued more for function than for any
                    expression of creativity or beauty", and set out to show it as a practical
                    alternative to plastic shopping bags. Its designers reworked the form in
                    pandan, buri, sabutan, rattan and leather: a pandan laptop bag, a bayong with
                    compartments for wet and dry goods, and organizers inspired by the tampipi,
                    the Filipino version of luggage.

                    The craft is still a household livelihood. In Sampaloc, Quezon, buri weavers
                    make baskets, handbags, hats, wallets, slippers, tampipi suitcases and
                    placemats, and the town's Bulihan Festival, begun by the local government in
                    2011, was created to revive an industry that was fading as fewer young people
                    took it up. In Luisiana, Laguna, a weavers' organization formed in 2019 counts
                    more than 300 members who plait bayong, banig mats and bags from pandan at home
                    and bring them to a shared hub. Its members also warn that pandan fields are
                    being cleared for other crops.

                    Every bayong still begins the same way: with leaves that are cut, stripped and
                    dried by hand before the first row is plaited.
                    TXT),
                'sources' => implode("\n", [
                    'Dulce Sanchez. Bayong: A fashionable alternative to plastic shopping bags. '
                        . 'The Philippine Star, 2009. https://www.philstar.com/business/agriculture/'
                        . '2009/05/17/467973/bayong-fashionable-alternative-plastic-shopping-bags',
                    'National Museum of the Philippines. Mats and Baskets. National Museum of the Philippines, '
                        . 'n.d. https://www.nationalmuseum.gov.ph/our-collections/ethnology/mats-and-baskets/',
                    'Mangyan Heritage Center. Gallery: Mangyan crafts. Mangyan Heritage Center, n.d. '
                        . 'https://mangyanheritage.odoo.com/mangyancrafts',
                    'Totel V. De Jesus. How buli weaving is kept alive and thriving in Sampaloc, Quezon. '
                        . 'ABS-CBN Lifestyle, 2026. https://www.abs-cbn.com/lifestyle/people-culture-events/'
                        . '2026/5/17/how-buli-weaving-is-kept-alive-and-thriving-in-sampaloc-quezon-1400',
                    'Elmer Valenzuela. The Women Weavers of Luisiana. Mata: Asia Press Photo, 2026. '
                        . 'https://matapress.com/2026/03/26/'
                        . 'national-womens-month-feature-the-women-weavers-of-luisiana/',
                ]),
            ],
            [
                'slug' => 'native-fibers',
                'title' => 'Buri, pandan, tikog and abaca',
                'excerpt' => 'Where the four native fibers of the collection come from, and how each is prepared for '
                    . 'weaving.',
                'body' => self::prose(<<<'TXT'
                    Four plants supply every bag in the collection. Buri, pandan and tikog are
                    among the materials the National Museum of the Philippines lists for the
                    country's mats and baskets, alongside bamboo and rattan. The fourth, abaca, is
                    the fiber that made the Philippines known worldwide for "Manila hemp".

                    Buri is a palm, Corypha elata, also published as Corypha utan. Its fan-shaped
                    leaves are among the largest of any Philippine palm and are prized for their
                    strength and versatility. In Sampaloc, Quezon, where the palm is called buli,
                    the leaves are harvested, stripped, sun-dried, boiled, dried again and
                    flattened before they are cut into strips. Weavers there reckon on three days
                    of summer sun before colouring, which takes about half an hour, and a leaf can
                    be woven within a week of harvest.

                    Pandan is the genus Pandanus. The species woven in Luisiana, Laguna, is
                    Pandanus simplex, and its leaves, stripped and dried in the sun, become bayong,
                    banig mats, bags and even chairs. A study of the town's pandan industry,
                    based on interviews with 73 local weavers, found the plant still abundant but
                    warned that poor harvesting, limited access to good raw material and a
                    shrinking number of traditional weavers put the craft at risk.

                    Tikog is a sedge of the genus Fimbristylis, often called a grass. Naturally
                    grown tikog needs no fertilizer and depends on clean water. In Basey, Samar,
                    weavers' associations now grow their own, pulling the stalks one by one rather
                    than cutting them; better farming has shortened the harvest cycle from six to
                    eight months to two or three. The stalks are dried, coloured and pressed flat
                    before plaiting, and the competency standards the weavers developed set the
                    strip size, the amount of dye, how long the leaves are boiled and how long
                    they dry.

                    Abaca is Musa textilis. Its fiber lies in the tuxy, the outer layer of the
                    leafsheath, and is extracted by pulling the tuxy under a knife, either by hand
                    or on a motor-driven spindle. The Philippine national standard grades normal
                    abaca at no less than 60 centimetres long, with a tensile strength of 35 to 55
                    kgf per gram. Catanduanes, the country's biggest producer, was declared the
                    Abaca Capital of the Philippines by Republic Act No. 11700 in 2022. As of
                    2025 the province counted 13,904 abaca farmers but only three processors.
                    TXT),
                'sources' => implode("\n", [
                    'National Museum of the Philippines. Mats and Baskets. National Museum of the Philippines, '
                        . 'n.d. https://www.nationalmuseum.gov.ph/our-collections/ethnology/mats-and-baskets/',
                    'Totel V. De Jesus. How buli weaving is kept alive and thriving in Sampaloc, Quezon. '
                        . 'ABS-CBN Lifestyle, 2026. https://www.abs-cbn.com/lifestyle/people-culture-events/'
                        . '2026/5/17/how-buli-weaving-is-kept-alive-and-thriving-in-sampaloc-quezon-1400',
                    'Marky Ramone Go. Through buri weaving and filmmaking, Sampaloc, Quezon tells a new story. '
                        . 'Rappler, 2026. https://www.rappler.com/people/artists/'
                        . 'buri-weaving-filmmaking-sampaloc-quezon/',
                    'Rusty Keith Placino et al. Environmental and Marketing Analysis of the Pandan '
                        . '(Pandanus simplex Merr.) Industry in Luisiana, Laguna as a Model to Revitalize '
                        . 'Traditional Weaving Craft in the Philippines. Springer Nature, 2026. '
                        . 'https://link.springer.com/chapter/10.1007/978-3-032-11252-1_9',
                    'Elmer Valenzuela. The Women Weavers of Luisiana. Mata: Asia Press Photo, 2026. '
                        . 'https://matapress.com/2026/03/26/'
                        . 'national-womens-month-feature-the-women-weavers-of-luisiana/',
                    'Lisa Inez Antonio. Basey Association for Native Industry Growth Inc. (BANIG): Weaving '
                        . 'Skills Revitalized for Sustainable Tikog Products. SWITCH-Asia, 2024. '
                        . 'https://www.switch-asia.eu/site/assets/files/4041/banig_philippines.pdf',
                    'International Labour Organization. A new wave of traditional mat-making sweeps through a '
                        . 'Philippine town. International Labour Organization, 2023. '
                        . 'https://www.ilo.org/resource/article/'
                        . 'new-wave-traditional-mat-making-sweeps-through-philippine-town',
                    'Bureau of Agriculture and Fisheries Standards. PNS/BAFS 180:2016 Abaca fiber - Grading and '
                        . 'Classification - Hand-stripped and Spindle/Machine-stripped. Department of Agriculture, '
                        . '2016. '
                        . 'https://philfida.da.gov.ph/images/Publications/PNS/'
                        . 'PNSBAFS1802016AbacaFiberHandstrippedandMachineStripped.pdf',
                    'Congress of the Philippines. Republic Act No. 11700: An Act Declaring the Province of '
                        . 'Catanduanes as the Abaca Capital of the Philippines. The Lawphil Project, 2022. '
                        . 'https://lawphil.net/statutes/repacts/ra2022/ra_11700_2022.html',
                    'Raffy Ayeng. PhilFIDA backs abaca revival in Catanduanes. Daily Tribune, 2026. '
                        . 'https://tribune.net.ph/2026/03/27/philfida-backs-abaca-revival-in-catanduanes',
                ]),
            ],
            [
                'slug' => 'weaving-traditions',
                'title' => 'Weaving traditions of the islands',
                'excerpt' => 'Plaiting and loom weaving, the two crafts behind Philippine mats, bags and cloth.',
                'body' => self::prose(<<<'TXT'
                    Philippine languages have long kept two kinds of weaving apart. In the
                    Mentrida lexicon, weaving cloth is paghabul, while lala is the plaiting of
                    reeds or leaf fronds into mats, banig, a word that also covers plaiting vines
                    into baskets. Archival sources record mats in use in the Visayas in the
                    seventeenth century. Today paglala, or paglara, is still the everyday word for
                    weaving a mat or a bag in towns from Quezon to Samar.

                    Mats carry meaning as well as sleepers. The National Museum of the Philippines
                    records mats laid out for drying grain and used to wrap the dead, and mats
                    that mark the dining area, divide space between families in communal houses
                    and houseboats, and define sacred ground or the place where marriages are
                    negotiated, feasts are held and disputes are settled.

                    Basey, Samar, is considered the banig capital of the Philippines. Its tikog
                    mats follow traditional designs with local names such as panyolito, bukid,
                    rikrak and karay karay, and some are embroidered. A standard mat can take up to
                    two weeks, intricate designs longer, and organizers of a 2008 banig exhibit by
                    the National Commission for Culture and the Arts described intricate mats shared
                    among several weavers, each working one section before the parts are joined.
                    Today about a thousand members, almost all women, from 23 barangays belong to
                    a federation that farms, dyes, weaves, embroiders and sews tikog, and turns
                    offcuts into bags, purses and slippers.

                    In Sampaloc, Quezon, buri weaving once paid for a generation's schooling. The
                    weaving itself is only the last step after days of preparing the leaf, and a
                    handbag is about a day's work, a hat about two. The town has a weaving centre,
                    and its Bulihan Festival has included weaving workshops for high-school students.
                    In Luisiana, Laguna, pandan weavers plait at home and turn their finished
                    pieces over to their organization's hub.

                    Loom weaving follows a different rhythm. On Catanduanes, a basic handloom
                    weaving workshop in Panganiban, run with DOST-PTRI, DTI, PhilFIDA and the local
                    government, trained eighteen women in 2025 to weave Philippine cotton and
                    Catanduanes abaca into handwoven textiles, and a permanent abaca weaving
                    centre is planned.

                    Plaited or loom-woven, each piece starts with the same slow work of preparing
                    leaf or fiber by hand.
                    TXT),
                'sources' => implode("\n", [
                    'Kaagi: Tracing Visayan Identities in Cultural Texts. Banig: Reed Weaving. '
                        . 'philippinestudies.uk, n.d. https://kaagi.philippinestudies.uk/banig/',
                    'National Museum of the Philippines. Mats and Baskets. National Museum of the Philippines, '
                        . 'n.d. https://www.nationalmuseum.gov.ph/our-collections/ethnology/mats-and-baskets/',
                    'International Labour Organization. A new wave of traditional mat-making sweeps through a '
                        . 'Philippine town. International Labour Organization, 2023. '
                        . 'https://www.ilo.org/resource/article/'
                        . 'new-wave-traditional-mat-making-sweeps-through-philippine-town',
                    'Lisa Inez Antonio. Basey Association for Native Industry Growth Inc. (BANIG): Weaving '
                        . 'Skills Revitalized for Sustainable Tikog Products. SWITCH-Asia, 2024. '
                        . 'https://www.switch-asia.eu/site/assets/files/4041/banig_philippines.pdf',
                    'Alexandra Jaca. The Culture of Mat Weaving. The Freeman (Philstar.com), 2008. '
                        . 'https://www.philstar.com/cebu-lifestyle/2008/05/31/64921/culture-mat-weaving',
                    'Marky Ramone Go. Through buri weaving and filmmaking, Sampaloc, Quezon tells a new story. '
                        . 'Rappler, 2026. https://www.rappler.com/people/artists/'
                        . 'buri-weaving-filmmaking-sampaloc-quezon/',
                    'Totel V. De Jesus. How buli weaving is kept alive and thriving in Sampaloc, Quezon. '
                        . 'ABS-CBN Lifestyle, 2026. https://www.abs-cbn.com/lifestyle/people-culture-events/'
                        . '2026/5/17/how-buli-weaving-is-kept-alive-and-thriving-in-sampaloc-quezon-1400',
                    'Elmer Valenzuela. The Women Weavers of Luisiana. Mata: Asia Press Photo, 2026. '
                        . 'https://matapress.com/2026/03/26/'
                        . 'national-womens-month-feature-the-women-weavers-of-luisiana/',
                    'HABI: The Philippine Textile Council. Weavers Rise Through Training at Panganiban Abaca '
                        . 'Weaving Center. HABI: The Philippine Textile Council, 2025. '
                        . 'https://www.habiphilippinetextilecouncil.com/blogs/habi-highlights/'
                        . 'weavers-rise-through-training-at-panganiban-abaca-weaving-center',
                ]),
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

    /**
     * Joins wrapped source lines into the stored format: one line per
     * paragraph, paragraphs separated by a blank line.
     */
    public static function prose(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/', trim($text)) ?: [];

        return implode("\n\n", array_map(
            static fn (string $paragraph): string => (string) preg_replace('/\s*\n\s*/', ' ', trim($paragraph)),
            $paragraphs
        ));
    }
}
