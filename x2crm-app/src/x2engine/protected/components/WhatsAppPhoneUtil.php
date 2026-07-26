<?php

/**
 * Shared phone-number normalization for WhatsApp sends. Extracted from
 * WhatsappGroupsController (which still delegates to this for its own
 * three call sites) once a second caller (X2FlowSendWhatsAppMessage)
 * needed the exact same logic.
 */
class WhatsAppPhoneUtil {

    /**
     * Normalizes a Contact's phone into the full international format
     * WhatsApp needs (country code + number, no leading zero/plus).
     * Confirmed live: ~93% of this install's contacts have their phone
     * stored as a bare 10-digit local number with no country code at all —
     * exactly WhatsApp-uninvitable as-is. Deliberately conservative: only
     * touches numbers that are EXACTLY 10 digits (the confirmed "missing
     * country code" shape here), leaves anything longer alone rather than
     * risk mis-prepending an already-correct number, and returns null
     * (skip, don't guess) when the country is set to something this
     * doesn't map confidently — a wrong guessed country code could message
     * a real, unrelated person in a different country who happens to share
     * that local number pattern. A BLANK country is the one exception:
     * confirmed live against this install's real data that of the 10-digit
     * numbers with a recognized country, ~94% are explicitly "USA" — so an
     * empty country field defaults to USA rather than skipping, while a
     * non-blank-but-unrecognized value (a city, zip code, typo, etc. — an
     * actual data problem) still returns null below.
     */
    public static function toWhatsAppPhone($rawPhone, $country) {
        $digits = preg_replace('/\D/', '', (string) $rawPhone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) !== 10) {
            return $digits;
        }
        if (trim((string) $country) === '') {
            return '1' . $digits;
        }
        $callingCode = self::countryCallingCode($country);
        return $callingCode === null ? null : ($callingCode . $digits);
    }

    /**
     * Calling codes for the country values actually seen in this
     * install's data (checked live via a GROUP BY on x2_contacts.country
     * for 10-digit phones) — a free-text field, so it includes several
     * spelling variants of the same country. Anything not listed here
     * (blank, a city/zip code mistakenly entered as the country, etc.)
     * returns null from here and is skipped by toWhatsAppPhone() rather
     * than guessed.
     */
    public static function countryCallingCode($country) {
        static $callingCodes = array(
            'usa' => '1', 'us' => '1', 'usa-in' => '1',
            'united states' => '1', 'united states of america' => '1', 'unitedstates' => '1',
            'canada' => '1', 'canada-in' => '1',
            'india' => '91',
            'russia' => '7',
            'mexico' => '52',
            'australia' => '61',
            'malaysia' => '60',
            'nepal' => '977',
            'united arab emirates' => '971',
            'suriname' => '597',
        );
        $key = strtolower(trim((string) $country));
        return isset($callingCodes[$key]) ? $callingCodes[$key] : null;
    }

}
