<?php
// =================================================================
//  helpers.php
//  Geografiske hjælpefunktioner.
//  Rettelse: fmod() i stedet for % (PHP 8.1-deprecation på floats).
// =================================================================

function beregnAfstand(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function beregnObjektPosition(
    float $lat1, float $lon1, float $azimuth1,
    float $lat2, float $lon2, float $azimuth2
): ?array {
    $avgLatRad = deg2rad(($lat1 + $lat2) / 2);
    $lonCorrection = cos($avgLatRad);

    // RETTELSE: fmod() — PHP 8.1+ klager hvis % bruges på floats.
    $angle1 = deg2rad(fmod(450 - $azimuth1, 360));
    $angle2 = deg2rad(fmod(450 - $azimuth2, 360));

    $m1 = tan($angle1);
    $m2 = tan($angle2);
    if (abs($m1 - $m2) < 1e-9) return null;

    $lon1_corr = $lon1 * $lonCorrection;
    $lon2_corr = $lon2 * $lonCorrection;

    $lon_intersect_corr =
        ($m1 * $lon1_corr - $m2 * $lon2_corr + $lat2 - $lat1) / ($m1 - $m2);
    $lat_intersect = $m1 * ($lon_intersect_corr - $lon1_corr) + $lat1;
    $lon_intersect = $lon_intersect_corr / $lonCorrection;

    return ['latitude' => $lat_intersect, 'longitude' => $lon_intersect];
}

function calculateBearing(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $lat1_rad = deg2rad($lat1);
    $lat2_rad = deg2rad($lat2);
    $delta_lon = deg2rad($lon2 - $lon1);
    $y = sin($delta_lon) * cos($lat2_rad);
    $x = cos($lat1_rad) * sin($lat2_rad)
       - sin($lat1_rad) * cos($lat2_rad) * cos($delta_lon);
    $bearing_rad = atan2($y, $x);
    return fmod(rad2deg($bearing_rad) + 360, 360);
}

function calculateDestinationPoint(
    float $lat, float $lon, float $bearing, float $distanceMeters
): array {
    $R = 6371000;
    $bearingRad = deg2rad($bearing);
    $latRad = deg2rad($lat);
    $lonRad = deg2rad($lon);
    $angDist = $distanceMeters / $R;
    $lat2 = asin(sin($latRad) * cos($angDist)
                + cos($latRad) * sin($angDist) * cos($bearingRad));
    $lon2 = $lonRad + atan2(
        sin($bearingRad) * sin($angDist) * cos($latRad),
        cos($angDist) - sin($latRad) * sin($lat2)
    );
    return ['latitude' => rad2deg($lat2), 'longitude' => rad2deg($lon2)];
}
