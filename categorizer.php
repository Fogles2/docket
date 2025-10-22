<?php
class Categorizer {
    private $crimeCategories = [
        'THEFT' => ['theft', 'stealing', 'shoplifting', 'larceny', 'robbery'],
        'DRUGS' => ['possession', 'distribution', 'controlled substance', 'heroin', 'cocaine', 'marijuana', 'meth'],
        'ASSAULT' => ['assault', 'battery', 'violence', 'aggravated'],
        'DUI' => ['dui', 'driving under the influence', 'dwi'],
        'WEAPONS' => ['weapon', 'firearm', 'gun', 'knife'],
        'SEX' => ['sexual', 'rape', 'molestation', 'indecent'],
        'FRAUD' => ['fraud', 'forgery', 'scam', 'embezzle'],
        'OTHER' => []
    ];

    // Enrich records: add crime_category and age_range to each record
    public function enrichRecords(array $records): array {
        $out = [];
        foreach ($records as $r) {
            $charge = isset($r['charge']) ? $r['charge'] : '';
            $dob = isset($r['dob']) ? $r['dob'] : null;
            $sex = isset($r['sex']) ? strtoupper(substr($r['sex'], 0, 1)) : '';

            $crimeCategory = $this->categorizeCrime($charge);
            $age = $this->calculateAge($dob);
            $ageRange = $this->getAgeRange($age);

            $out[] = [
                'case_number' => $r['case_number'] ?? '',
                'defendant'   => $r['defendant'] ?? '',
                'dob'         => $dob ?? '',
                'sex'         => $sex,
                'charge'      => $charge,
                'crime_category' => $crimeCategory,
                'age_range'      => $ageRange,
            ];
        }
        return $out;
    }

    // Optional: produce summary counts from an array of records (not used for DB inserts)
    public function categorizeSummary(array $records): array {
        $summary = ['crimes' => [], 'ages' => [], 'sex' => ['M' => 0, 'F' => 0, 'Unknown' => 0]];
        foreach ($this->enrichRecords($records) as $r) {
            $summary['crimes'][$r['crime_category']] = ($summary['crimes'][$r['crime_category']] ?? 0) + 1;
            $summary['ages'][$r['age_range']] = ($summary['ages'][$r['age_range']] ?? 0) + 1;
            if ($r['sex'] === 'M' || $r['sex'] === 'F') $summary['sex'][$r['sex']]++;
            else $summary['sex']['Unknown']++;
        }
        return $summary;
    }

    private function categorizeCrime($charge) {
        $charge = strtolower((string)$charge);
        foreach ($this->crimeCategories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && strpos($charge, $keyword) !== false) {
                    return $category;
                }
            }
        }
        return 'OTHER';
    }

    private function calculateAge($dob) {
        if (!$dob) return null;
        $d = DateTime::createFromFormat('m/d/Y', $dob);
        if (!$d) {
            // try alternative formats
            $d = DateTime::createFromFormat('Y-m-d', $dob);
        }
        if (!$d) return null;
        $today = new DateTime();
        return $today->diff($d)->y;
    }

    private function getAgeRange($age) {
        if ($age === null) return 'Unknown';
        if ($age < 18) return 'Under 18';
        if ($age < 25) return '18-24';
        if ($age < 35) return '25-34';
        if ($age < 50) return '35-49';
        return '50+';
    }
}