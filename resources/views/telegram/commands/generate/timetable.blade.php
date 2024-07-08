<strong>{{ __('📚 TIMETABLE') }}</strong>
<?php
/* --------------------------------------------------------------------------------------------------
 * Use PHP tags instead of Blade tags to prevent unwanted whitespace when sending a Telegram message.
 * --------------------------------------------------------------------------------------------------
 */
foreach ($coursesTimetable as $day => $timetable) {
    echo "\n<strong>" . __('🗓 :day', ['day' => $day]) . "</strong>\n";

    foreach ($timetable as $key => $value) {

        echo __(':course_code 🕛 :start_time - :end_time', [
            'course_code' => $value['course_code'],
            'start_time'  => optional($value['start_time'])->format('H:i a'),
            'end_time'    => optional($value['end_time'])->format('H:i a'),
        ]);

        if ($value['is_clash'] === true) {
            echo __(' ❗️ <strong>CLASHED</strong> ❗️');
        }

        echo "\n";
    }
}
?>
