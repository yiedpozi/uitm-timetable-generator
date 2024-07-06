<?php

namespace App\Services;

use App\Libraries\Icress\IcressAPI;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class IcressService
{
    private $icress;

    public function __construct()
    {
        $this->icress = new IcressAPI();
    }

    // Get campuses
    public function getCampuses()
    {
        $cacheKey = 'icress_campuses';
        $campuses = Cache::get($cacheKey);

        if (!$campuses) {
            try {
                $campuses = array();

                list($code, $response) = $this->icress->getCampuses();

                $results = optional($response)['results'];

                if ($results && is_array($results)) {
                    foreach ($results as $campus) {
                        // Skip divider
                        if ($campus['id'] === 'X') {
                            continue;
                        }

                        // Remove campus ID from its name
                        $campuses[$campus['id']] = Str::replaceStart($campus['id'] . ' - ', '', $campus['text']);
                    }
                }

                // Cache campuses list for 1 month
                if ($campuses) {
                    Cache::put($cacheKey, $campuses, now()->addMonth());
                }
            } catch (Exception $e) {
            }
        }

        return $campuses;
    }

    // Get faculties (for Selangor campus)
    public function getFaculties()
    {
        $cacheKey = 'icress_faculties';
        $faculties = Cache::get($cacheKey);

        if (!$faculties) {
            try {
                $faculties = array();

                list($code, $response) = $this->icress->getFaculties();

                $results = optional($response)['results'];

                if ($results && is_array($results)) {
                    foreach ($results as $faculty) {
                        // Remove faculty ID from its name
                        $faculties[$faculty['id']] = Str::replaceStart($faculty['id'] . ' - ', '', $faculty['text']);
                    }
                }

                // Cache faculties list for 1 month
                if ($faculties) {
                    Cache::put($cacheKey, $faculties, now()->addMonth());
                }
            } catch (Exception $e) {
            }
        }

        return $faculties;
    }

    // Get subjects code and URL for specified campus and faculty
    public function getSubjects($campus, $faculty = null)
    {
        $cacheKey = 'icress_subjects_' . $campus;

        if ($faculty) {
            $cacheKey .= '_' . $faculty;
        }

        $subjects = Cache::get($cacheKey);

        if (!$subjects) {
            try {
                list($code, $response) = $this->icress->getSubjects($campus, $faculty);

                if ($code === 200) {
                    $subjects = array();

                    // Add line break for every new <tr>, so we can use preg match to match the data for each row
                    $response = str_replace("<tr", "\n<tr", $response);

                    // Regex pattern to get course code and timetable URL from table row
                    $pattern = "/<td>([A-Z]+\d+).*<\/td>.*onClick.*\('(.*)'\)/";

                    preg_match_all($pattern, $response, $matches);

                    // Pair subject code with its URL
                    if (isset($matches[1]) && isset($matches[2])) {
                        $subjects = array_combine($matches[1], $matches[2]);
                    }

                    // Cache subjects list for 1 month
                    if ($subjects) {
                        Cache::put($cacheKey, $subjects, now()->addMonth());
                    }
                }
            } catch (Exception $e) {
            }
        }

        return $subjects;
    }

    // Get subject timetable
    public function getTimetable($campus, $faculty, $subject, $group = null)
    {
        $cacheKey = 'icress_timetable_' . $subject;
        $timetable = Cache::get($cacheKey);

        if (!$timetable) {
            try {
                $subjects = $this->getSubjects($campus, $faculty);
                $subjectUrl = optional($subjects)[$subject];

                parse_str(parse_url($subjectUrl, PHP_URL_QUERY), $subjectUrlQuery);

                if (isset($subjectUrlQuery['id1']) && isset($subjectUrlQuery['id2'])) {

                    list($code, $response) = $this->icress->getTimetable(
                        $subjectUrlQuery['id1'],
                        $subjectUrlQuery['id2']
                    );

                    if ($code === 200) {
                        $timetable = array();

                        // Add line break for every new <tr>, so we can use preg match to match the data for each row
                        $response = str_replace("<tr", "\n<tr", $response);

                        // Regex pattern to get table rows and columns
                        $trPattern = '/<tr[^>]*>(.*?)<\/tr>/s';
                        $tdPattern = '/<td[^>]*>(.*?)<\/td>/';

                        preg_match_all($trPattern, $response, $rows);

                        if (isset($rows[1])) {
                            foreach ($rows[1] as $rowIndex => $row) {
                                preg_match_all($tdPattern, $row, $columns);

                                $currentRowColumns = isset($columns[1]) ? $columns[1] : array();

                                if ($currentRowColumns) {
                                    $time     = strip_tags(optional($currentRowColumns)[1]);
                                    $group    = strip_tags(optional($currentRowColumns)[2]);
                                    $location = strip_tags(optional($currentRowColumns)[5]);

                                    $day       = null;
                                    $startTime = null;
                                    $endTime   = null;

                                    // Extract day, start time and end time
                                    $pattern = '/([A-Z]+)\( (\d+:\d+ [AMP]+)-(\d+:\d+ [AMP]+)/';

                                    if (preg_match($pattern, $time, $matches)) {
                                        $day       = $matches[1];
                                        $startTime = $matches[2];
                                        $endTime   = $matches[3];
                                    }

                                    $timetable[] = [
                                        'day'        => $day,
                                        'start_time' => $startTime,
                                        'end_time'   => $endTime,
                                        'group'      => $group,
                                        'location'   => $location,
                                    ];
                                }
                            }
                        }

                        // Cache subject timetable for 1 day
                        if ($timetable) {
                            Cache::put($cacheKey, $timetable, now()->addDay());
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        return $timetable;
    }
}
