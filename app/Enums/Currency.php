<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ISO 4217 currencies the PMS renders amounts in. Per-property —
 * a multi-branch franchise can run branches in different countries.
 *
 * Display-only for now: amounts stay stored as integer minor units
 * (naira today, pesewas after Ghana rollout, cents everywhere else)
 * and get rendered with the property's picked symbol + decimals.
 * No FX conversion between properties — each branch operates in its
 * own currency and reports independently.
 *
 * Grouped alphabetically-by-code within each region.
 */
enum Currency: string
{
    // ── West Africa ──
    case NGN = 'NGN';   // Nigerian naira
    case GHS = 'GHS';   // Ghanaian cedi
    case XOF = 'XOF';   // West African CFA franc (BEN, BFA, CIV, GNB, MLI, NER, SEN, TGO)
    case GMD = 'GMD';   // Gambian dalasi
    case GNF = 'GNF';   // Guinean franc
    case LRD = 'LRD';   // Liberian dollar
    case SLL = 'SLL';   // Sierra Leonean leone
    case CVE = 'CVE';   // Cape Verdean escudo

    // ── East Africa ──
    case KES = 'KES';   // Kenyan shilling
    case UGX = 'UGX';   // Ugandan shilling
    case TZS = 'TZS';   // Tanzanian shilling
    case RWF = 'RWF';   // Rwandan franc
    case BIF = 'BIF';   // Burundian franc
    case ETB = 'ETB';   // Ethiopian birr
    case DJF = 'DJF';   // Djiboutian franc
    case ERN = 'ERN';   // Eritrean nakfa
    case SOS = 'SOS';   // Somali shilling
    case SSP = 'SSP';   // South Sudanese pound
    case SDG = 'SDG';   // Sudanese pound

    // ── Central Africa ──
    case XAF = 'XAF';   // Central African CFA franc (CMR, CAF, TCD, COG, GNQ, GAB)
    case CDF = 'CDF';   // Congolese franc
    case STN = 'STN';   // São Tomé and Príncipe dobra

    // ── Southern Africa ──
    case ZAR = 'ZAR';   // South African rand
    case BWP = 'BWP';   // Botswana pula
    case NAD = 'NAD';   // Namibian dollar
    case LSL = 'LSL';   // Lesotho loti
    case SZL = 'SZL';   // Swazi lilangeni
    case MWK = 'MWK';   // Malawian kwacha
    case ZMW = 'ZMW';   // Zambian kwacha
    case MZN = 'MZN';   // Mozambican metical
    case AOA = 'AOA';   // Angolan kwanza

    // ── North Africa ──
    case EGP = 'EGP';   // Egyptian pound
    case MAD = 'MAD';   // Moroccan dirham
    case DZD = 'DZD';   // Algerian dinar
    case TND = 'TND';   // Tunisian dinar
    case LYD = 'LYD';   // Libyan dinar
    case MRU = 'MRU';   // Mauritanian ouguiya

    // ── Island nations ──
    case KMF = 'KMF';   // Comorian franc
    case MGA = 'MGA';   // Malagasy ariary
    case MUR = 'MUR';   // Mauritian rupee
    case SCR = 'SCR';   // Seychellois rupee

    // ── World: majors ──
    case USD = 'USD';
    case EUR = 'EUR';
    case GBP = 'GBP';
    case CAD = 'CAD';
    case AUD = 'AUD';
    case JPY = 'JPY';
    case CNY = 'CNY';   // Chinese yuan
    case INR = 'INR';   // Indian rupee
    case CHF = 'CHF';   // Swiss franc
    case SGD = 'SGD';   // Singapore dollar
    case HKD = 'HKD';   // Hong Kong dollar
    case KRW = 'KRW';   // South Korean won
    case BRL = 'BRL';   // Brazilian real
    case MXN = 'MXN';   // Mexican peso
    case TRY = 'TRY';   // Turkish lira

    // ── Gulf ──
    case AED = 'AED';   // UAE dirham
    case SAR = 'SAR';   // Saudi riyal
    case QAR = 'QAR';   // Qatari riyal
    case KWD = 'KWD';   // Kuwaiti dinar
    case BHD = 'BHD';   // Bahraini dinar
    case OMR = 'OMR';   // Omani rial

    /**
     * Currency symbol — shown as the prefix on rendered amounts.
     * Falls back to the ISO code for currencies without a widely-
     * recognised single-glyph symbol (dinars, ouguiyas, etc.).
     */
    public function symbol(): string
    {
        return match ($this) {
            self::NGN                          => '₦',
            self::GHS                          => 'GH₵',
            self::KES, self::UGX, self::TZS,
            self::SOS, self::SSP               => 'Sh',
            self::ZAR, self::LSL, self::SZL    => 'R',
            self::EGP, self::SDG               => 'E£',
            self::USD, self::CAD, self::AUD,
            self::SGD, self::HKD, self::LRD,
            self::MXN, self::NAD               => '$',
            self::EUR                          => '€',
            self::GBP                          => '£',
            self::JPY, self::CNY               => '¥',
            self::INR                          => '₹',
            self::KRW                          => '₩',
            self::TRY                          => '₺',
            self::BRL                          => 'R$',
            self::MAD                          => 'DH',
            self::AED                          => 'د.إ',
            self::SAR                          => 'ر.س',
            self::CHF                          => 'CHF',
            self::CVE                          => 'Esc',
            self::BWP                          => 'P',
            self::MWK, self::ZMW               => 'K',
            self::MZN                          => 'MT',
            self::AOA                          => 'Kz',
            self::MGA                          => 'Ar',
            self::MUR, self::SCR               => '₨',
            self::ETB                          => 'Br',
            self::GMD                          => 'D',
            self::STN                          => 'Db',
            default                            => $this->value,
        };
    }

    /**
     * Human-friendly name — used in dropdowns.
     */
    public function label(): string
    {
        return match ($this) {
            self::NGN => 'Nigerian naira',
            self::GHS => 'Ghanaian cedi',
            self::XOF => 'West African CFA franc',
            self::GMD => 'Gambian dalasi',
            self::GNF => 'Guinean franc',
            self::LRD => 'Liberian dollar',
            self::SLL => 'Sierra Leonean leone',
            self::CVE => 'Cape Verdean escudo',
            self::KES => 'Kenyan shilling',
            self::UGX => 'Ugandan shilling',
            self::TZS => 'Tanzanian shilling',
            self::RWF => 'Rwandan franc',
            self::BIF => 'Burundian franc',
            self::ETB => 'Ethiopian birr',
            self::DJF => 'Djiboutian franc',
            self::ERN => 'Eritrean nakfa',
            self::SOS => 'Somali shilling',
            self::SSP => 'South Sudanese pound',
            self::SDG => 'Sudanese pound',
            self::XAF => 'Central African CFA franc',
            self::CDF => 'Congolese franc',
            self::STN => 'São Tomé and Príncipe dobra',
            self::ZAR => 'South African rand',
            self::BWP => 'Botswana pula',
            self::NAD => 'Namibian dollar',
            self::LSL => 'Lesotho loti',
            self::SZL => 'Swazi lilangeni',
            self::MWK => 'Malawian kwacha',
            self::ZMW => 'Zambian kwacha',
            self::MZN => 'Mozambican metical',
            self::AOA => 'Angolan kwanza',
            self::EGP => 'Egyptian pound',
            self::MAD => 'Moroccan dirham',
            self::DZD => 'Algerian dinar',
            self::TND => 'Tunisian dinar',
            self::LYD => 'Libyan dinar',
            self::MRU => 'Mauritanian ouguiya',
            self::KMF => 'Comorian franc',
            self::MGA => 'Malagasy ariary',
            self::MUR => 'Mauritian rupee',
            self::SCR => 'Seychellois rupee',
            self::USD => 'US dollar',
            self::EUR => 'Euro',
            self::GBP => 'British pound sterling',
            self::CAD => 'Canadian dollar',
            self::AUD => 'Australian dollar',
            self::JPY => 'Japanese yen',
            self::CNY => 'Chinese yuan',
            self::INR => 'Indian rupee',
            self::CHF => 'Swiss franc',
            self::SGD => 'Singapore dollar',
            self::HKD => 'Hong Kong dollar',
            self::KRW => 'South Korean won',
            self::BRL => 'Brazilian real',
            self::MXN => 'Mexican peso',
            self::TRY => 'Turkish lira',
            self::AED => 'UAE dirham',
            self::SAR => 'Saudi riyal',
            self::QAR => 'Qatari riyal',
            self::KWD => 'Kuwaiti dinar',
            self::BHD => 'Bahraini dinar',
            self::OMR => 'Omani rial',
        };
    }

    /**
     * Decimal places used when formatting. Most currencies have 2;
     * the zero-decimal currencies below don't split into minor units.
     */
    public function decimals(): int
    {
        return match ($this) {
            // The PMS treats amounts as whole units for these — naira
            // is nominally 100 kobo but kobo aren't used in practice;
            // yen / won have no retail sub-unit; CFA and franc-block
            // currencies don't have centimes.
            self::NGN,
            self::JPY, self::KRW,
            self::XOF, self::XAF, self::KMF, self::DJF,
            self::BIF, self::GNF, self::MGA, self::RWF,
            self::UGX               => 0,
            // Gulf currencies split into 1000 fils / baisa.
            self::BHD, self::KWD,
            self::OMR               => 3,
            default                 => 2,
        };
    }

    /**
     * Loose parse helper used by settings / setup form binding.
     */
    public static function tryFromCode(?string $code): ?self
    {
        if ($code === null) return null;
        return self::tryFrom(strtoupper(trim($code)));
    }

    /**
     * Region grouping for the currency picker so the dropdown shows
     * "West Africa" / "East Africa" / etc. instead of a flat list.
     *
     * @return array<string, list<self>>
     */
    public static function byRegion(): array
    {
        return [
            'West Africa'    => [self::NGN, self::GHS, self::XOF, self::GMD, self::GNF, self::LRD, self::SLL, self::CVE],
            'East Africa'    => [self::KES, self::UGX, self::TZS, self::RWF, self::BIF, self::ETB, self::DJF, self::ERN, self::SOS, self::SSP, self::SDG],
            'Central Africa' => [self::XAF, self::CDF, self::STN],
            'Southern Africa'=> [self::ZAR, self::BWP, self::NAD, self::LSL, self::SZL, self::MWK, self::ZMW, self::MZN, self::AOA],
            'North Africa'   => [self::EGP, self::MAD, self::DZD, self::TND, self::LYD, self::MRU],
            'Islands'        => [self::KMF, self::MGA, self::MUR, self::SCR],
            'World: majors'  => [self::USD, self::EUR, self::GBP, self::CAD, self::AUD, self::JPY, self::CNY, self::INR, self::CHF, self::SGD, self::HKD, self::KRW, self::BRL, self::MXN, self::TRY],
            'Gulf'           => [self::AED, self::SAR, self::QAR, self::KWD, self::BHD, self::OMR],
        ];
    }
}
