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

    private const SELANGOR_CAMPUS_ID = 'B';

    // Class constructor
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

    // Returns true if the campus requires selecting faculty when generating a timetable
    public function isFacultyRequired($campusId)
    {
        $campuses = $this->getCampuses();

        // Only Selangor campus requires selecting faculty when generating a timetable
        return $campusId === self::SELANGOR_CAMPUS_ID;
    }

    // Get courses code and URL for specified campus and faculty
    public function getCourses($campusId, $facultyId = null)
    {
        $cacheKey = 'icress_courses:' . $campusId;

        if ($facultyId) {
            $cacheKey .= '_' . $facultyId;
        }

        $courses = Cache::get($cacheKey);

        if (!$courses) {
            try {
                list($code, $response) = $this->icress->getCourses($campusId, $facultyId);

                if ($code === 200) {
                    $courses = array();

                    // Add line break for every new <tr>, so we can use preg match to match the data for each row
                    $response = str_replace("<tr", "\n<tr", $response);

                    // Regex pattern to get course code and timetable URL from table row
                    $pattern = "/<td>([A-Z]+\d+).*<\/td>.*onClick.*\('(.*)'\)/";

                    preg_match_all($pattern, $response, $matches);

                    // Pair course code with its URL
                    if (isset($matches[1]) && isset($matches[2])) {
                        $courses = array_combine($matches[1], $matches[2]);
                    }

                    // Cache courses list for 1 month
                    if ($courses) {
                        Cache::put($cacheKey, $courses, now()->addMonth());
                    }
                }
            } catch (Exception $e) {
            }
        }

        return $courses;
    }

    // Get the timetable for specified course
    public function getCourseTimetable($campusId, $facultyId, $courseCode, $group = null)
    {
        $courses = $this->getCourses($campusId, $facultyId);
        $courseUrl = optional($courses)[$courseCode];

        if (!$courseUrl) {
            return false;
        }

        $cacheKey = 'icress_timetable:' . $courseCode;
        $timetable = Cache::get($cacheKey);

        if (!$timetable) {
            try {
                // Extract course URL parameters
                parse_str(parse_url($courseUrl, PHP_URL_QUERY), $courseUrlQuery);

                if (isset($courseUrlQuery['id1']) && isset($courseUrlQuery['id2'])) {

                    list($code, $response) = $this->icress->getCourseTimetable(
                        $courseUrlQuery['id1'],
                        $courseUrlQuery['id2']
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
                                    $columnTime     = strip_tags(optional($currentRowColumns)[1]);
                                    $columnGroup    = strip_tags(optional($currentRowColumns)[2]);
                                    $columnLocation = strip_tags(optional($currentRowColumns)[5]);

                                    $columnDay       = null;
                                    $columnStartTime = null;
                                    $columnEndTime   = null;

                                    // Extract day, start time and end time
                                    $pattern = '/([A-Z]+)\( (\d+:\d+ [AMP]+)-(\d+:\d+ [AMP]+)/';

                                    if (preg_match($pattern, $columnTime, $matches)) {
                                        $columnDay       = $matches[1];
                                        $columnStartTime = $this->parseTime($matches[2]);
                                        $columnEndTime   = $this->parseTime($matches[3]);
                                    }

                                    $timetable[] = [
                                        'day'         => $columnDay,
                                        'start_time'  => $columnStartTime,
                                        'end_time'    => $columnEndTime,
                                        'course_code' => $courseCode,
                                        'group'       => $columnGroup,
                                        'location'    => $columnLocation,
                                    ];
                                }
                            }
                        }

                        // Cache course timetable for 1 day
                        if ($timetable) {
                            Cache::put($cacheKey, $timetable, now()->addDay());
                        }
                    }
                }
            } catch (Exception $e) {
            }
        }

        // Filters by course group
        if ($timetable && $group) {
            $timetable = array_filter($timetable, function($value) use ($group) {
                return $value['group'] === $group;
            });
        }

        return $timetable;
    }

    // Get formatted timetable for specified course
    public function getFormattedCoursesTimetable($campusId, $facultyId, array $courses)
    {
        $timetables = array();
        $formattedTimetables = array();

        foreach ($courses as $courseCode => $group) {
            $timetables[] = $this->getCourseTimetable($campusId, $facultyId, $courseCode, $group);
        }

        // Track the occurrence of unique identifiers for clash checking
        $occurrences = [];

        $formattedTimetables = collect($timetables)->collapse()

            // Check for any clash
            ->map(function($item) use (&$occurrences) {
                $identifier = $item['day'] . '|' . $item['start_time'];

                if (isset($occurrences[$identifier])) {
                    $occurrences[$identifier]++;
                } else {
                    $occurrences[$identifier] = 1;
                }

                $item['is_clash'] = $occurrences[$identifier] > 1;

                return $item;
            })

            // Sort by start time
            ->sortBy([
                function(array $a, array $b) {
                    return $a['start_time'] <=> $b['start_time'];
                }
            ])

            // Group by day
            ->groupBy(function(array $item, int $key) {
                return $item['day'];
            })
            ->all();

        return $formattedTimetables;
    }

    // Search campus ID by its name
    public function getCampusIdByName($campusName)
    {
        // Make it case insensitive by converting the input value to uppercase.
        $campusName = strtoupper($campusName);
        $campuses = array_map('strtoupper', $this->getCampuses());

        return optional(array_flip($campuses))[$campusName];
    }

    // Search faculty ID by its name
    public function getFacultyIdByName($facultyName)
    {
        // Make it case insensitive by converting the input value to uppercase.
        $facultyName = strtoupper($facultyName);
        $faculties = array_map('strtoupper', $this->getFaculties());

        return optional(array_flip($faculties))[$facultyName];
    }

    // Convert time string (eg: "10:00 AM") to Carbon object
    private function parseTime($time)
    {
        $time = explode(':', substr($time, 0, 5));

        $hour = optional($time)[0];
        $minute = optional($time)[1];

        if (!$hour || !$minute) {
            return false;
        }

        return now()
            ->setTimezone('Asia/Kuala_Lumpur')
            ->setTime($hour, $minute);
    }
}
