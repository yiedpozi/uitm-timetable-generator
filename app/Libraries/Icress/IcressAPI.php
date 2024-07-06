<?php

namespace App\Libraries\Icress;

class IcressAPI extends IcressClient
{
    // Get campuses
    public function getCampuses()
    {
        return $this->get('cfc/select.cfc', [
            'method' => 'find_cam_icress_student',
            'key'    => 'All',
            'page'   => 1,
            'page'   => 30,
        ]);
    }

    // Get faculties (for Selangor campus)
    public function getFaculties()
    {
        return $this->get('cfc/select.cfc', [
            'method' => 'find_fac_icress_student',
            'key'    => 'All',
            'page'   => 1,
            'page'   => 30,
        ]);
    }

    // Get subjects code and URL for specified campus and faculty
    public function getSubjects($campus, $faculty = null)
    {
        $this->setHeader('Referer', config('uitmtimetable.icress_referer_url'));

        return $this->post('index_result.cfm', [
            'search_campus'  => $campus,
            'search_faculty' => $faculty,
            'search_course'  => '',
        ]);
    }

    // Get subject timetable
    public function getTimetable($id1, $id2)
    {
        return $this->get('index_tt.cfm', [
            'id1' => $id1,
            'id2' => $id2,
        ]);
    }
}
