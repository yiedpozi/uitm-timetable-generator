<?php

return [
    'telegram_bot' => [
        // Text / keyboard placeholder
        'select_campus'      => 'Select your campus.',
        'select_faculty'     => 'Select your faculty.',
        'enter_courses_code' => "Please enter your course code and group in the following format:\n\nCourse Code - Group\n\nPlease provide one line per course code.\n\nExample:\nENT530 - D1IM2443A\nIMS605 - D1IM2455A",

        // Error messages
        'invalid_campus'  => 'Invalid campus selected. Please select a valid campus.',
        'invalid_faculty' => 'Invalid faculty selected. Please select a valid faculty.',
        'invalid_courses' => "Invalid course code formats. Please enter your course code with its group based on provided format.\n\nExample:\nENT530 - D1IM2443A\nIMS605 - D1IM2455A",
    ],
];
