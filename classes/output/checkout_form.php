<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Class containing data for index page
 *
 * @package    enrol_pagseguro
 * @copyright  2020 Igor Agatti Lima
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace enrol_pagseguro\output;

defined('MOODLE_INTERNAL') || die;


require_once("$CFG->dirroot/webservice/externallib.php");

use renderable;
use templatable;
use renderer_base;
use stdClass;
use external_api;

/**
 * Class containing data for pagseguro modal form
 *
 * @copyright  2020 Igor Agatti Lima
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checkout_form implements renderable, templatable {


    /** @var array $formparams */
    public $formparams = array();

    /**
     * Constructor class that sets formparams.
     *
     * @param array $fparams
     * @return stdClass
     */
    public function __construct(array $fparams = array()) {
        $this->formparams = $fparams;
    }

    /**
     * Export this data so it can be used as the context for a mustache template.
     *
     * @param renderer_base $output
     * @return stdClass $dataobj
     */
    public function export_for_template(renderer_base $output) {
        global $USER, $COURSE, $PAGE, $DB;

        $data = array();
        
        $coupon = $this->check_discount_code($this->formparams['couponcode'], $this->formparams['courseId']);
        
        $data["courseid"] = $this->formparams['courseId'];
        $course_enrol = $DB->get_record("enrol", array('enrol' => 'pagseguro', 'courseid' => $this->formparams['courseId'] ));
        $data["email"] = $USER->email;
        $data["fullname"] = $USER->firstname." ".$USER->lastname;
        if ($USER->cpf) {
            $data["cpf"] = $USER->cpf;
        }
        if ($USER->phone) {
            $data["phone"] = $USER->phone;
        }
        $data["dt"] = userdate(time()) . ' ' . rand();
        $data["cost"] = $course_enrol->cost;
        if(!empty($coupon)) {
            $data['couponcode'] = $coupon['data']['couponcode'];
            $data['coupontype'] = $coupon['data']['coupontype'];
            $data['couponvalue'] = $coupon['data']['coupondiscount'];
            if($coupon->type == 'value'){
                $data['liqdiscount'] = $data['couponvalue'];
            } else {
                $data['liqdiscount'] = ($data['couponvalue']/100)*$data['cost'];
            }
            $data["cost"] = $course_enrol->cost - $data['liqdiscount'];
        }
        $dataobj = json_encode($data);
        return $dataobj;
    }
    
    private function check_discount_code($couponcode, $courseid){
        if (!empty($couponcode)) {
            $args = array('couponcode' => $couponcode, 'courseid' => $courseid );
            if (external_api::call_external_function('enrol_coupon_validate_coupon', $args)) {
                $args = array('couponcode' => $couponcode);
                return external_api::call_external_function('enrol_coupon_get_coupon_by_code', $args);
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

}
