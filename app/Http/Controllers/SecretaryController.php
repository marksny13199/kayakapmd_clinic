<?php

namespace App\Http\Controllers;

use App\Models\HMOModel;
use App\Models\SecretaryModel;
use DateTime;
use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\ConsultationModel;
use App\Models\ConsultationAnswerModel;
use App\Models\DoctorQuestionsModel;
use App\Models\DoctorsProfileModel;
use App\Models\ScheduleModel;
use App\Models\SecretaryDoctorsModel;
use App\Models\SettlementsModel;
use App\Models\ServicesGroupManagementModel;
use App\Models\ServicesManagementModel;
use App\Models\Stocks\StocksLedgerModel;
use App\Models\PatientMasterlist;
use App\Models\PatientClassificationModel;
use App\Models\PxMedicalHistoryModel;
use App\Models\KayakapProfileModel;
// Detailed Comment: Legacy clinical models used as defensive fallback for historical consultations in fetchPatientMedhistory
use App\Models\DocChargesModel;
use App\Models\DocRequestsModel;
use App\Models\DoctorMedicinesModel;
use App\Models\DiagnosticsMasterlistModel;
use App\Models\Libraries\MDiseaseLibModel;
use App\Models\ProfileModel;
use App\Models\Profiles\ProfileMedHistModel;
use App\Models\Profiles\ProfileMhSpecificModel;
use App\Models\Profiles\ProfileSurgHistModel;
use App\Services\ClientService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SecretaryController extends Controller
{
    public function index()
    {
        return redirect()->route('secretary.queue');
    }

    public function queuePage()
    {
        // Detailed Comment: Resolve authenticated user across secretary and admin guards.
        // Admins see all doctors; secretaries only see assigned doctors.
        $secUser = auth()->guard('secretary')->user();
        $isAdmin = auth()->guard('admin')->check();

        if ($isAdmin) {
            $doctors = DoctorsProfileModel::select(['docrefno', 'docname', 'specializations', 'expertise'])->get();
        } else {
            $assigned_doctors = $secUser ? SecretaryDoctorsModel::where([
                'secrefno' => $secUser->secrefno
            ])->pluck('docrefno') : collect();

            $doctors = DoctorsProfileModel::select(['docrefno', 'docname', 'specializations', 'expertise'])
                ->whereIn('docrefno', $assigned_doctors)
                ->get();
        }

        $secretaries = SecretaryModel::select(['secrefno', 'seclname', 'secfname'])->get();

        // Detailed Comment: Patient classifications from lib_hci_class (CLASS_CODE, CLASS_DEF) for the
        // secretary consultation form. The table has no status column, so there is no "active" scope.
        $patientClassifications = PatientClassificationModel::orderBy('CLASS_DEF')->get();

        // Detailed Comment: Active disease library entries for Yakap Medical History modal checklist
        $mDiseaseLibrary = MDiseaseLibModel::where('lib_stat', 1)
            ->orderBy('sort_no')
            ->get();

        Log::info('Secretary queue page rendered', [
            'is_admin' => $isAdmin,
            'secretary_id' => $secUser ? $secUser->id : null,
            'secrefno' => $secUser ? $secUser->secrefno : null,
            'assigned_doctors_count' => $doctors->count(),
            'mdisease_count' => $mDiseaseLibrary->count(),
            'patient_classifications_count' => $patientClassifications->count(),
        ]);

        return view('pages.secretary.queue', [
            'doctors' => $doctors,
            'secretaries' => $secretaries,
            'patientClassifications' => $patientClassifications,
            'mDiseaseLibrary' => $mDiseaseLibrary,
        ]);
    }

    // for loading new reference and patient codes
    public function generateNewCodes()
    {
        return response()->json([
            'refCode' => $this->generateReferenceCode(),
            'patientCode' => $this->generatePatientCode()
        ]);
    }

    private function generateReferenceCode()
    {
        // Format: CRNW + MMDDYYYY + HHMMSS (Philippines timezone)
        $now = Carbon::now('Asia/Manila');
        return 'CRNW' . $now->format('mdYHis');
    }

    private function generatePatientCode()
    {
        // Format: PTNW + MMDDYYYY + HHMMSS (Philippines timezone)
        $now = Carbon::now('Asia/Manila');
        return 'PTNW' . $now->format('mdYHis');
    }

    /**
     * Detailed Comment: Builds a unique-ish reference number: MMDDYYYYHHMMSS + suffix + 2 random digits,
     * so two records created in the same second no longer collide.
     */
    private function makeRefNo(string $suffix): string
    {
        return Date::now()->format('mdYHis') . $suffix . random_int(10, 99);
    }

    /**
     * Detailed Comment: Display name of the current actor across secretary/admin guards.
     */
    private function currentActorName(): string
    {
        if (auth()->guard('secretary')->check()) {
            return auth()->guard('secretary')->user()->seclname ?? 'Secretary';
        }
        if (auth()->guard('admin')->check()) {
            return auth()->guard('admin')->user()->name ?? 'Administrator';
        }
        return 'System';
    }

    // Management
    public function management()
    {
        // NOTE: if this throws "View not found", change to 'pages.secretary.management' (queuePage uses the pages. prefix).
        return view('secretary.management');
    }

    public function fetchDoctorsFromSecretary()
    {
        // Detailed Comment: Secretaries get assigned doctors only; admins get all; anyone else is rejected.
        $secUser = auth()->guard('secretary')->user();
        if ($secUser) {
            $assigned_doctors = SecretaryDoctorsModel::where(['secrefno' => $secUser->secrefno])->pluck('docrefno');
            $doctors = DoctorsProfileModel::select(['docrefno', 'docname'])->whereIn('docrefno', $assigned_doctors)->get();
        } elseif (auth()->guard('admin')->check()) {
            $doctors = DoctorsProfileModel::select(['docrefno', 'docname'])->get();
        } else {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        return response()->json(['success' => true, 'doctors' => $doctors]);
    }

    // Question-related
    // Detailed Comment: Fetch doctor questions with flexible parameter support (docrefno or docid) and dual response keys
    public function fetchQuestions(Request $request, $docid = null)
    {
        $refno = $request->input('docrefno') ?: $request->input('docid') ?: $docid ?: $request->route('docid');
        $docquestions = DoctorQuestionsModel::select(['docquestionrefno', 'question', 'docname', 'docrefno', 'recordeddate', 'recordedby'])
            ->where('docrefno', $refno)
            ->get();

        return response()->json([
            'success' => true,
            'docquestions' => $docquestions,
            'questions' => $docquestions
        ]);
    }

    // Detailed Comment: Alias for fetching questions by doctor reference/ID for contract parity
    public function fetchDoctorQuestions(Request $request, $docid = null)
    {
        return $this->fetchQuestions($request, $docid);
    }

    // Detailed Comment: Fetch a single doctor question by docquestionrefno or ID
    public function fetchQuestionByRef(Request $request, $id = null)
    {
        $refno = $request->input('docquestionrefno') ?: $request->input('id') ?: $id ?: $request->route('id');
        $question = DoctorQuestionsModel::where('docquestionrefno', $refno)->first();
        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Doctor question not found.'], 404);
        }

        return response()->json(['success' => true, 'question' => $question]);
    }

    // Detailed Comment: Create a new doctor question; authorizes admin and secretary guards, resolves doctor full name
    public function createQuestion(Request $request)
    {
        $docrefno = $request->input('docrefno') ?: $request->input('docid');
        if ($docrefno) {
            $request->merge(['docrefno' => $docrefno]);
        }

        $request->validate([
            'docrefno' => 'required|string',
            'question' => 'required|string',
        ]);

        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $doctor = DoctorsProfileModel::where('docrefno', $request->docrefno)->first();
        if (!$doctor) {
            return response()->json(['success' => false, 'message' => 'Doctor profile not found.'], 404);
        }

        $docname = $doctor->docname ?: trim(implode(' ', array_filter([$doctor->docfname, $doctor->docmname, $doctor->doclname, $doctor->suffix])));
        if (!$docname) {
            $docname = 'Doctor';
        }

        $secrefno = $user->adminrefno ?? $user->secrefno ?? 'ADMIN';
        $recordedby = $user->username ?? 'admin';

        $question = DoctorQuestionsModel::create([
            'docquestionrefno' => $this->makeRefNo('QT'),
            'secrefno' => $secrefno,
            'question' => $request->question,
            'docrefno' => $request->docrefno,
            'docname' => $docname,
            'recordeddate' => Date::now(),
            'recordedby' => $recordedby,
        ]);

        Log::info('Doctor question created', [
            'docquestionrefno' => $question->docquestionrefno,
            'docrefno' => $request->docrefno,
            'recordedby' => $recordedby,
        ]);

        return response()->json(['success' => true, 'question' => $question]);
    }

    // Detailed Comment: Edit an existing doctor question by reference number
    public function editQuestion(Request $request)
    {
        $refno = $request->input('docquestionrefno') ?: $request->input('id') ?: $request->route('id');
        if ($refno) {
            $request->merge(['docquestionrefno' => $refno]);
        }

        $request->validate([
            'docquestionrefno' => 'required|string',
            'question' => 'required|string',
        ]);

        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $question = DoctorQuestionsModel::where('docquestionrefno', $request->docquestionrefno)->first();
        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Question not found.'], 404);
        }

        $question->update([
            'question' => $request->question,
        ]);

        Log::info('Doctor question updated', [
            'docquestionrefno' => $request->docquestionrefno,
            'updatedby' => $user->username ?? 'admin',
        ]);

        return response()->json(['success' => true, 'question' => $question]);
    }

    // Detailed Comment: Delete a doctor question by reference number or route ID
    public function deleteQuestion(Request $request, $id = null)
    {
        $refno = $request->input('docquestionrefno') ?: $request->input('id') ?: $id ?: $request->route('id');
        if ($refno) {
            $request->merge(['docquestionrefno' => $refno]);
        }

        $request->validate([
            'docquestionrefno' => 'required|string',
        ]);

        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $question = DoctorQuestionsModel::where('docquestionrefno', $request->docquestionrefno)->first();
        if (!$question) {
            return response()->json(['success' => false, 'message' => 'Question not found.'], 404);
        }

        $question->delete();

        Log::info('Doctor question deleted', [
            'docquestionrefno' => $request->docquestionrefno,
            'deletedby' => $user->username ?? 'admin',
        ]);

        return response()->json(['success' => true]);
    }

    // Detailed Comment: Schedule-related operations for physicians
    public function fetchSchedules(Request $request, $docid = null)
    {
        $refno = $request->input('docrefno') ?: $request->input('docid') ?: $docid ?: $request->route('docid');
        if ($refno) {
            $request->merge(['docrefno' => $refno]);
        }

        $request->validate([
            'docrefno' => 'required|string'
        ]);

        // Detailed Comment: Order by natural day of the week (Sunday..Saturday), then by start time
        $dayOrderSql = "CASE day
            WHEN 'Sunday' THEN 1
            WHEN 'Monday' THEN 2
            WHEN 'Tuesday' THEN 3
            WHEN 'Wednesday' THEN 4
            WHEN 'Thursday' THEN 5
            WHEN 'Friday' THEN 6
            WHEN 'Saturday' THEN 7
            ELSE 8
        END ASC, start ASC";

        $schedules = ScheduleModel::where('docrefno', $request->docrefno)
            ->orderByRaw($dayOrderSql)
            ->get();

        return response()->json([
            'success' => true,
            'schedules' => $schedules
        ]);
    }

    // Detailed Comment: Parity alias for fetching doctor schedules
    public function fetchDoctorSchedules(Request $request, $docid = null)
    {
        return $this->fetchSchedules($request, $docid);
    }

    /**
     * Detailed Comment: Fetch a doctor's clinic time slots for the weekday of the requested consultation date.
     * - No doctor selected -> returns doctor_selected = false so the UI can prompt the user.
     * - Unparsable date -> falls back to today instead of throwing HTTP 500.
     * - Also returns `weekday` and the doctor's distinct `available_days`.
     */
    public function fetchSchedulesSpecific(Request $request)
    {
        $docrefno = trim((string) $request->input('docrefno', ''));

        try {
            $consulDate = $request->consuldate ? new DateTime($request->consuldate) : new DateTime();
        } catch (\Exception $e) {
            Log::warning('fetchSchedulesSpecific received an invalid consultation date', [
                'consuldate' => $request->consuldate,
                'error' => $e->getMessage(),
            ]);
            $consulDate = new DateTime();
        }
        $weekday = $consulDate->format('l');

        if ($docrefno === '') {
            return response()->json([
                'success' => true,
                'doctor_selected' => false,
                'weekday' => $weekday,
                'schedules' => [],
                'available_days' => [],
            ]);
        }

        $schedules = ScheduleModel::where([
            'docrefno' => $docrefno,
            'day' => $weekday
        ])->orderBy('start', 'ASC')->get();

        $weekOrder = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $availableDays = ScheduleModel::where('docrefno', $docrefno)
            ->distinct()
            ->pluck('day')
            ->filter()
            ->sortBy(fn($day) => array_search($day, $weekOrder, true))
            ->values();

        return response()->json([
            'success' => true,
            'doctor_selected' => true,
            'weekday' => $weekday,
            'schedules' => $schedules,
            'available_days' => $availableDays,
        ]);
    }

    // Detailed Comment: Get specific schedule by schedrefno or route parameter
    public function fetchScheduleByRef(Request $request, $schedrefno = null)
    {
        $refno = $request->input('schedrefno') ?: $request->input('id') ?: $schedrefno ?: $request->route('schedrefno');
        $sched = ScheduleModel::where('schedrefno', $refno)->first();
        if (!$sched) {
            return response()->json(['success' => false, 'message' => 'Schedule not found.'], 404);
        }

        return response()->json(['success' => true, 'sched' => $sched, 'schedule' => $sched]);
    }

    // Detailed Comment: Edit doctor schedule; authenticates admin/secretary and validates existence
    public function editSchedule(Request $request)
    {
        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $schedrefno = $request->input('schedrefno') ?: $request->input('id');
        if ($schedrefno) {
            $request->merge(['schedrefno' => $schedrefno]);
        }

        $request->validate([
            'schedrefno' => 'required|string',
            'day' => 'required|in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'start' => 'required',
            'end' => 'required'
        ]);

        $sched = ScheduleModel::where('schedrefno', $request->schedrefno)->first();
        if (!$sched) {
            return response()->json(['success' => false, 'message' => 'Schedule not found.'], 404);
        }

        $updateData = [
            'day' => $request->day,
            'start' => $request->start,
            'end' => $request->end,
        ];
        if ($request->has('notes') && Schema::hasColumn('docschedules', 'notes')) {
            $updateData['notes'] = $request->notes;
        }

        $sched->update($updateData);

        // Detailed Comment: Re-synchronize doctor's clinichours with updated docschedules
        $doctorProfile = DoctorsProfileModel::where('docrefno', $sched->docrefno)->first();
        if ($doctorProfile) {
            $doctorProfile->syncClinicHoursFromSchedules();
        }

        Log::info('Doctor schedule updated', [
            'schedrefno' => $request->schedrefno,
            'day' => $request->day,
            'start' => $request->start,
            'end' => $request->end,
            'updatedby' => $user->username ?? 'admin'
        ]);

        return response()->json(['success' => true, 'sched' => $sched, 'schedule' => $sched]);
    }

    // Detailed Comment: Create doctor schedule; supports both frontend keys (day, stime, etime) and API keys
    public function createSchedule(Request $request)
    {
        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $docrefno = $request->input('docrefno') ?: $request->input('docid');
        $day = $request->input('schedule_day') ?: $request->input('day');
        $from = $request->input('sched_from') ?: $request->input('start') ?: $request->input('stime') ?: $request->input('start_time');
        $to = $request->input('sched_to') ?: $request->input('end') ?: $request->input('etime') ?: $request->input('end_time');
        $notes = $request->input('notes');

        $request->merge([
            'docrefno' => $docrefno,
            'schedule_day' => $day,
            'sched_from' => $from,
            'sched_to' => $to
        ]);

        $request->validate([
            'docrefno' => 'required|string',
            'schedule_day' => 'required|in:Sunday,Monday,Tuesday,Wednesday,Thursday,Friday,Saturday',
            'sched_from' => 'required',
            'sched_to' => 'required',
        ]);

        $doctor = DoctorsProfileModel::where('docrefno', $docrefno)->first();
        if (!$doctor) {
            return response()->json(['success' => false, 'message' => 'Doctor profile not found.'], 404);
        }

        // env() must not be used outside config files (breaks with config:cache); add 'client_code' to config/app.php
        $facilityClientCode = $user->clientcode ?? config('app.client_code', '122377');
        $schedrefno = Date::now()->format('mdYHis') . 'SCHED' . rand(10, 99);

        $scheduleData = [
            'dw_clientcode' => $facilityClientCode,
            'schedrefno' => $schedrefno,
            'docrefno' => $request->docrefno,
            'day' => $request->schedule_day,
            'start' => $request->sched_from,
            'end' => $request->sched_to,
        ];

        if ($notes !== null && Schema::hasColumn('docschedules', 'notes')) {
            $scheduleData['notes'] = $notes;
        }

        $schedule = ScheduleModel::create($scheduleData);

        $doctor->syncClinicHoursFromSchedules();

        Log::info('Doctor schedule created', [
            'schedrefno' => $schedrefno,
            'docrefno' => $request->docrefno,
            'day' => $request->schedule_day,
            'start' => $request->sched_from,
            'end' => $request->sched_to,
            'createdby' => $user->username ?? 'admin'
        ]);

        return response()->json(['success' => true, 'schedule' => $schedule]);
    }

    // Detailed Comment: Delete doctor schedule; authenticates admin/secretary and deletes by schedrefno or route ID
    public function deleteSchedule(Request $request, $id = null)
    {
        $user = auth()->guard('admin')->user() ?? auth()->guard('secretary')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $schedrefno = $request->input('schedrefno') ?: $request->input('id') ?: $id ?: $request->route('id');
        if ($schedrefno) {
            $request->merge(['schedrefno' => $schedrefno]);
        }

        $request->validate([
            'schedrefno' => 'required|string'
        ]);

        $sched = ScheduleModel::where('schedrefno', $request->schedrefno)->first();
        if (!$sched) {
            return response()->json(['success' => false, 'message' => 'Schedule not found.'], 404);
        }

        $docRefToDelete = $sched->docrefno;
        $sched->delete();

        if ($docRefToDelete) {
            $doctorProfile = DoctorsProfileModel::where('docrefno', $docRefToDelete)->first();
            if ($doctorProfile) {
                $doctorProfile->syncClinicHoursFromSchedules();
            }
        }

        Log::info('Doctor schedule deleted', [
            'schedrefno' => $request->schedrefno,
            'deletedby' => $user->username ?? 'admin'
        ]);

        return response()->json(['success' => true]);
    }

    // Service Group Management

    public function createGroupManagement(Request $request)
    {
        ServicesGroupManagementModel::create([
            'servicegroup_refno' => $this->makeRefNo('GRP'),
            'servicegroup_name' => $request->servicegroup_name,
            'servicegroup_dscr' => $request->servicegroup_dscr
        ]);

        return response()->json(['success' => true]);
    }

    public function fetchGroupManagement(Request $request)
    {
        $groupManagement = ServicesGroupManagementModel::select(
            'servicegroup_refno',
            'servicegroup_name',
            'servicegroup_dscr'
        )->get()->map(function ($row) {
            return [
                'token' => encrypt($row->servicegroup_refno),
                'servicegroup_refno' => $row->servicegroup_refno,
                'servicegroup_name' => $row->servicegroup_name,
                'servicegroup_dscr' => $row->servicegroup_dscr,
            ];
        });

        return response()->json([
            'groupManagement' => $groupManagement
        ]);
    }

    // Detailed Comment: decrypt() is inside try so an invalid/tampered token returns a clean 400 instead of HTTP 500
    public function editGroupManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            if (empty($token)) {
                return response()->json(['status' => false, 'message' => 'Empty Token']);
            }

            $result = ServicesGroupManagementModel::where('servicegroup_refno', $token)->first();
            if (!$result) {
                return response()->json(['status' => false, 'message' => 'Group not found'], 404);
            }

            return response()->json(['status' => true, 'message' => $result]);
        } catch (DecryptException $e) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 400);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function deleteGroupManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            if (empty($token)) {
                return response()->json(['status' => false, 'message' => 'Empty Token']);
            }

            $group = ServicesGroupManagementModel::where('servicegroup_refno', $token)->first();

            if (!$group) {
                return response()->json(['status' => false, 'message' => 'Group not found']);
            }

            $group->delete();

            return response()->json(['status' => true, 'message' => 'Group deleted successfully']);
        } catch (DecryptException $e) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 400);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function updateGroupManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            if (empty($token)) {
                return response()->json(['status' => false, 'message' => 'Empty Token']);
            }

            $group = ServicesGroupManagementModel::where('servicegroup_refno', $token)->first();

            if (!$group) {
                return response()->json(['status' => false, 'message' => 'Group not found']);
            }

            $group->servicegroup_name = $request->servicegroup_name;
            $group->servicegroup_dscr = $request->servicegroup_dscr;
            $group->save();

            return response()->json([
                'status' => true,
                'message' => 'Group updated successfully',
                'data' => $group,
            ]);
        } catch (DecryptException $e) {
            return response()->json(['status' => false, 'message' => 'Invalid token'], 400);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => $e->getMessage()]);
        }
    }

    public function fetchGroupManagementCategory()
    {
        $groupManagement = ServicesGroupManagementModel::select(
            'servicegroup_refno',
            'servicegroup_name',
            'servicegroup_dscr'
        )->get()->map(function ($row) {
            return [
                'servicegroup_refno' => $row->servicegroup_refno,
                'servicegroup_name' => $row->servicegroup_name,
                'servicegroup_dscr' => $row->servicegroup_dscr,
            ];
        });

        return response()->json([
            'groupManagement' => $groupManagement
        ]);
    }

    public function createServicesManagement(Request $request)
    {
        try {
            $request->validate([
                'servicename' => 'required|string|max:255',
                'servicecharge' => 'required|numeric|min:0',
                'category' => 'required|string|max:255',
                'servicedscr' => 'nullable|string|max:1000',
                'docrefno' => 'required|string|max:50',
            ]);

            $service = ServicesManagementModel::create([
                'servicerefno' => $this->makeRefNo('SVC'),
                'servicename' => $request->servicename,
                'servicecharge' => $request->servicecharge,
                'category' => $request->category,
                'servicedscr' => $request->servicedscr,
                'docrefno' => $request->docrefno,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Service added successfully',
                'data' => $service,
            ]);
        } catch (ValidationException $e) {
            // Detailed Comment: Let Laravel return proper 422 field errors instead of swallowing them
            throw $e;
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function fetchDoctorServices(Request $request)
    {
        $services = ServicesManagementModel::all();
        $doctors = DoctorsProfileModel::select('docrefno', 'docfname', 'doclname')->get()->keyBy('docrefno');

        $result = $services->map(function ($service) use ($doctors) {
            $doctor = $doctors->get($service->docrefno);

            return [
                'token' => encrypt($service->servicerefno),
                'servicerefno' => $service->servicerefno,
                'servicename' => $service->servicename,
                'servicedscr' => $service->servicedscr,
                'servicecharge' => $service->servicecharge,
                'category' => $service->category,
                'docrefno' => $service->docrefno,
                'docfullname' => $doctor ? $doctor->docfname . ' ' . $doctor->doclname : 'N/A',
            ];
        });

        return response()->json([
            'doctorServices' => $result
        ]);
    }

    public function editServiceManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            if (!$token) {
                return response()->json(['status' => false, 'message' => 'Empty token']);
            }

            $service = ServicesManagementModel::where('servicerefno', $token)->first();

            if (!$service) {
                return response()->json(['status' => false, 'message' => 'Service not found']);
            }

            return response()->json(['status' => true, 'data' => $service]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid payload']);
        }
    }

    public function deleteServiceManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            $service = ServicesManagementModel::where('servicerefno', $token)->first();

            if (!$service) {
                return response()->json(['status' => false, 'message' => 'Service not found']);
            }

            $service->delete();

            return response()->json(['status' => true, 'message' => 'Service deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid payload']);
        }
    }

    public function updateServiceManagement(Request $request)
    {
        try {
            $token = decrypt($request->token);

            if (!$token) {
                return response()->json(['status' => false, 'message' => 'Empty token']);
            }

            $service = ServicesManagementModel::where('servicerefno', $token)->first();

            if (!$service) {
                return response()->json(['status' => false, 'message' => 'Service not found']);
            }

            $service->servicename = $request->servicename;
            $service->servicecharge = $request->servicecharge;
            $service->category = $request->category;
            $service->servicedscr = $request->servicedscr;
            $service->save();

            return response()->json([
                'status' => true,
                'message' => 'Service updated successfully',
                'data' => $service
            ]);
        } catch (\Exception $e) {
            return response()->json(['status' => false, 'message' => 'Invalid payload']);
        }
    }

    public function fetchSecretary(Request $request)
    {
        $user = auth()->guard('secretary')->user();
        if ($user) {
            $user->source_table = 'secretaryrights';
        }

        return response()->json(['success' => true, 'user' => $user]);
    }

    /**
     * Detailed Comment: Self-service profile update for authenticated secretary,
     * strictly bound to the authenticated secretary's secrefno to prevent cross-user tampering.
     */
    public function updateSecretaryProfile(Request $request)
    {
        $secAuth = auth()->guard('secretary')->user();
        if (!$secAuth) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated secretary'], 401);
        }

        $request->validate([
            'secfname' => 'required|string|max:100',
            'seclname' => 'required|string|max:100',
            'username' => 'required|string|max:50|unique:secretaryrights,username,' . $secAuth->id,
            'secpassword' => 'nullable|string|min:5',
            'secemail' => 'nullable|email|max:100',
            'seccontactno' => 'nullable|string|max:20',
            'secgender' => 'nullable|string|in:male,female,MALE,FEMALE',
            'secbday' => 'nullable|date'
        ]);

        $updateData = [
            'secfname' => $request->secfname,
            'secmname' => $request->secmname,
            'seclname' => $request->seclname,
            'secsuffix' => $request->secsuffix,
            'secgender' => strtoupper($request->secgender ?? $secAuth->secgender ?? 'MALE'),
            'secbday' => $request->secbday,
            'seccontactno' => $request->seccontactno,
            'secemail' => $request->secemail,
            'secadrs' => $request->secadrs,
            'username' => strtolower(trim($request->username))
        ];

        if ($request->filled('secpassword')) {
            $updateData['secpassword'] = Hash::make($request->secpassword);
        }

        SecretaryModel::where('secrefno', $secAuth->secrefno)->update($updateData);

        Log::info('Secretary self-service profile updated', [
            'secrefno' => $secAuth->secrefno,
            'username' => $request->username
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Profile updated successfully.'
        ]);
    }

    /**
     * Detailed Comment: Fetches comprehensive consultation and medical history for a patient in secretary queue.
     * Returns the full consultation modal dataset (vitals, impressions, diagnosis, admission instructions,
     * medications, diagnostics, lab/radiology files, charges).
     * Stocks ledger rows are bulk pre-loaded once (no per-consultation N+1 queries).
     */
    public function fetchPatientMedhistory(Request $request)
    {
        $pincode = $request->input('pincode');
        $pxrefno = $request->input('pxrefno');
        $consultationrefno = $request->input('consultationrefno');
        $patientname = $request->input('patientname');
        $birthday = $request->input('birthday');

        $pxrefnoList = [];
        $pincodeList = [];

        if (!empty($pxrefno) && $pxrefno !== 'undefined') {
            $pxrefnoList[] = $pxrefno;
        }
        if (!empty($pincode) && $pincode !== 'undefined') {
            $pincodeList[] = $pincode;
        }

        if (!empty($consultationrefno) && $consultationrefno !== 'undefined') {
            $activeConsult = ConsultationModel::where('consultationrefno', $consultationrefno)->first();
            if ($activeConsult) {
                if (!empty($activeConsult->pxrefno)) $pxrefnoList[] = $activeConsult->pxrefno;
                if (!empty($activeConsult->pincode)) $pincodeList[] = $activeConsult->pincode;
                if (empty($patientname)) $patientname = $activeConsult->patientname;
                if (empty($birthday)) $birthday = $activeConsult->birthday;
            }
        }

        // Cross-reference with pxmasterlist to find associated pincode/pxrefno
        $masterMatch = null;
        if (!empty($pxrefnoList)) {
            $masterMatch = PatientMasterlist::whereIn('pxrefno', $pxrefnoList)->first();
        }
        if (!$masterMatch && !empty($pincodeList)) {
            $masterMatch = PatientMasterlist::whereIn('pincode', $pincodeList)->first();
        }
        if ($masterMatch) {
            if (!empty($masterMatch->pxrefno)) $pxrefnoList[] = $masterMatch->pxrefno;
            if (!empty($masterMatch->pincode)) $pincodeList[] = $masterMatch->pincode;
            if (empty($birthday) && !empty($masterMatch->birthday)) $birthday = $masterMatch->birthday;
            if (empty($patientname) && !empty($masterMatch->patientname)) $patientname = $masterMatch->patientname;
        }

        $pxrefnoList = array_values(array_unique(array_filter($pxrefnoList)));
        $pincodeList = array_values(array_unique(array_filter($pincodeList)));

        $hasIdentifier = !empty($pxrefnoList) || !empty($pincodeList) || (!empty($patientname) && !empty($birthday)) || (!empty($consultationrefno) && $consultationrefno !== 'undefined');

        if (!$hasIdentifier) {
            return response()->json([
                'draw' => intval($request->draw),
                'recordsFiltered' => 0,
                'recordsTotal' => 0,
                'success' => true,
                'history' => [],
                'medhistory' => [],
                'data' => []
            ]);
        }

        $query = ConsultationModel::query();
        $query->where(function ($sub) use ($pxrefnoList, $pincodeList, $patientname, $birthday, $consultationrefno) {
            $hasAny = false;
            if (!empty($pxrefnoList)) {
                $sub->whereIn('pxrefno', $pxrefnoList);
                $hasAny = true;
            }
            if (!empty($pincodeList)) {
                if ($hasAny) {
                    $sub->orWhereIn('pincode', $pincodeList);
                } else {
                    $sub->whereIn('pincode', $pincodeList);
                    $hasAny = true;
                }
            }
            if (!empty($patientname) && !empty($birthday)) {
                if ($hasAny) {
                    $sub->orWhere(function ($q) use ($patientname, $birthday) {
                        $q->where('patientname', $patientname)->where('birthday', $birthday);
                    });
                } else {
                    $sub->where('patientname', $patientname)->where('birthday', $birthday);
                    $hasAny = true;
                }
            }
            if (!$hasAny && !empty($consultationrefno)) {
                $sub->where('consultationrefno', $consultationrefno);
            }
        });

        // 'address' is intentionally omitted from select() (MySQL 1054 on pxwalkinconsultation);
        // it is resolved from pxmasterlist during transformation.
        $history = $query->with('patient')
            ->select([
                'id',
                'consultationrefno',
                'pxrefno',
                'pincode',
                'caseno',
                'patientname',
                'gender',
                'birthday',
                'age',
                'mobilenumber',
                'emailaddress',
                'photo_path',
                'consultation_date',
                'reasonforconsultation',
                'brief_history_present_illness',
                'pertinent_past_medhistory',
                'impression',
                'finadiagnosis',
                'instructions',
                'foradmit',
                'foradmit_instructions',
                'weight',
                'wunit',
                'height',
                'hunit',
                'temp',
                'tempunit',
                'respiratoryrate',
                'pulserate',
                'bpnumerator',
                'bpdenominator',
                'docname',
                'docrefno',
                'classification',
                'subclassification',
                'hmocode',
                'hmoname',
                'phic_pin',
                'status',
                'queueno',
                'recordedby',
                'recordeddate',
                'laboratorypath',
                'radiologypath'
            ])
            ->orderBy('id', 'desc')
            ->get();

        // Bulk pre-load answered doctor questions
        $allConsultationRefNos = $history->pluck('consultationrefno')->filter()->values()->toArray();
        $allAnswers = ConsultationAnswerModel::leftJoin('docquestion', 'walkinconsultation_answer.questionrefno', '=', 'docquestion.docquestionrefno')
            ->whereIn('walkinconsultation_answer.pxconsultationrefno', $allConsultationRefNos)
            ->select('walkinconsultation_answer.*', 'docquestion.question')
            ->get()
            ->groupBy('pxconsultationrefno');

        // Bulk pre-load stocks ledger rows (Rx, diagnostics, charges) once for all consultations
        $allLedger = StocksLedgerModel::whereIn('px_consultcode_cn', $allConsultationRefNos)
            ->select(['id', 'px_consultcode_cn', 'prodcode', 'item_dscr', 'item_grouping', 'qty', 'cost_ave', 'retails', 'totalamt', 'remarks', 'instructions', 'dispensed_status', 'dispensed', 'updated'])
            ->get()
            ->groupBy('px_consultcode_cn');

        // Bulk pre-load legacy fallbacks only for consultations that have no ledger rows
        $refsWithoutLedger = array_values(array_diff($allConsultationRefNos, $allLedger->keys()->toArray()));
        $legacyMeds = !empty($refsWithoutLedger)
            ? DoctorMedicinesModel::whereIn('consultationrefno', $refsWithoutLedger)->get()->groupBy('consultationrefno')
            : collect();
        $legacyReqs = !empty($refsWithoutLedger)
            ? DocRequestsModel::whereIn('consultationrefno', $refsWithoutLedger)->get()->groupBy('consultationrefno')
            : collect();
        $legacyCharges = !empty($refsWithoutLedger)
            ? DocChargesModel::whereIn('consultationrefno', $refsWithoutLedger)->get()->groupBy('consultationrefno')
            : collect();
        $masterDiagModels = $legacyReqs->isNotEmpty()
            ? DiagnosticsMasterlistModel::whereIn('diagnosticrefno', $legacyReqs->flatten(1)->pluck('requestrefno')->filter()->unique()->toArray())
            ->get()
            ->keyBy('diagnosticrefno')
            : collect();

        // Bulk pre-load permanent medical history records
        $allPxRefNos = $history->pluck('pxrefno')->filter()->merge($pxrefnoList)->unique()->values()->toArray();
        $allPinCodes = $history->pluck('pincode')->filter()->merge($pincodeList)->unique()->values()->toArray();

        $allMedHistories = PxMedicalHistoryModel::where(function ($q) use ($allPxRefNos, $allPinCodes) {
            if (!empty($allPxRefNos)) {
                $q->whereIn('pxrefno', $allPxRefNos);
            }
            if (!empty($allPinCodes)) {
                $q->orWhereIn('pincode', $allPinCodes);
            }
        })->get();

        $primaryMedHistory = $allMedHistories->first();

        $history->transform(function ($record) use (
            $allAnswers,
            $allMedHistories,
            $primaryMedHistory,
            $allLedger,
            $legacyMeds,
            $legacyReqs,
            $legacyCharges,
            $masterDiagModels
        ) {
            $ref = $record->consultationrefno;

            $record->answers = $allAnswers->get($ref, collect());

            $recordMedHistory = $allMedHistories->first(function ($mh) use ($record) {
                return (!empty($record->pxrefno) && $mh->pxrefno === $record->pxrefno) ||
                    (!empty($record->pincode) && $mh->pincode === $record->pincode);
            }) ?? $primaryMedHistory;
            $record->permanent_medhistory = $recordMedHistory;

            // Defensive patient resolution: eager-loaded relation first, then pxmasterlist lookup
            $patientMaster = $record->patient ?? (
                (!empty($record->pxrefno) || !empty($record->pincode))
                ? PatientMasterlist::where(function ($q) use ($record) {
                    if (!empty($record->pxrefno)) {
                        $q->where('pxrefno', $record->pxrefno);
                    }
                    if (!empty($record->pincode)) {
                        $q->orWhere('pincode', $record->pincode);
                    }
                })->first()
                : null
            );

            if ($patientMaster) {
                $addrParts = array_filter([
                    $patientMaster->streetadrs,
                    $patientMaster->brgy,
                    $patientMaster->muncity,
                    $patientMaster->province
                ]);
                $record->address = !empty($addrParts) ? implode(', ', $addrParts) : ($patientMaster->address ?? 'N/A');

                if (empty($record->pincode)) {
                    $record->pincode = $patientMaster->pincode ?: ($patientMaster->phic_pin ?: 'N/A');
                }
                if (empty($record->pxrefno)) {
                    $record->pxrefno = $patientMaster->pxrefno ?: 'N/A';
                }
                if (empty($record->birthday)) {
                    $record->birthday = $patientMaster->birthday;
                }
                if (empty($record->gender)) {
                    $record->gender = $patientMaster->gender ?: 'N/A';
                }
                if (empty($record->mobilenumber)) {
                    $record->mobilenumber = $patientMaster->mobilenumber ?: 'N/A';
                }
                if (empty($record->patientname) && !empty($patientMaster->patientname)) {
                    $record->patientname = $patientMaster->patientname;
                }
                if (empty($record->photo_path) && !empty($patientMaster->photo_path)) {
                    $record->photo_path = $patientMaster->photo_path;
                }
            } else {
                $record->address = 'N/A';
            }

            // Contract aliases for frontend components
            $record->pin = $record->pincode;
            $record->ref = $record->pxrefno;
            $record->contactno = $record->mobilenumber;
            $record->sex = $record->gender;

            if (empty($record->age) && !empty($record->birthday)) {
                try {
                    $record->age = Carbon::parse($record->birthday)->age;
                } catch (\Throwable $e) {
                    $record->age = null;
                }
            }

            if ($record->photo_path && !str_contains($record->photo_path, 'blank_photo.png')) {
                $filename = basename($record->photo_path);
                $record->photo_path = url('/patient/photo/' . $filename);
                $record->photo_url = url('/patient/photo/' . $filename);
            } else {
                $record->photo_path = url('/images/blank_photo.png');
                $record->photo_url = url('/images/blank_photo.png');
            }

            $record->radiology_url = (!empty($record->radiologypath) && is_string($record->radiologypath))
                ? route('preview.file', ['path' => $record->radiologypath])
                : null;
            $record->laboratory_url = (!empty($record->laboratorypath) && is_string($record->laboratorypath))
                ? route('preview.file', ['path' => $record->laboratorypath])
                : null;

            // Ledger rows for this consultation (already loaded in bulk)
            $ledger = $allLedger->get($ref, collect());

            // Prescriptions (Rx) with fallback to legacy DoctorMedicinesModel
            $rxItems = $ledger->where('item_grouping', 'DRUGS AND MEDS')->values()
                ->map(function ($rx) {
                    $rx = clone $rx;
                    $rx->dispensed_flag = ($rx->dispensed_status === 'RELEASED' || !empty($rx->dispensed)) ? 1 : 0;
                    $rx->med_name = $rx->item_dscr;
                    $rx->dosage = $rx->remarks;
                    $rx->quantity = $rx->qty;
                    $rx->is_dispensed = ($rx->dispensed_flag == 1);
                    return $rx;
                });

            if ($rxItems->isEmpty()) {
                $record->rx_items = $legacyMeds->get($ref, collect())->map(function ($docRx) {
                    return (object)[
                        'prodcode' => $docRx->medicinecode ?? 'RX',
                        'item_dscr' => $docRx->medicinename,
                        'med_name' => $docRx->medicinename,
                        'qty' => $docRx->medicinequantity ?: 1,
                        'quantity' => $docRx->medicinequantity ?: 1,
                        'remarks' => $docRx->medicinedosage ?? 'As directed',
                        'dosage' => $docRx->medicinedosage ?? 'As directed',
                        'instructions' => $docRx->medicineduration ?? '',
                        'dispensed_status' => 'PENDING',
                        'dispensed' => null,
                        'dispensed_flag' => 0,
                        'is_dispensed' => false,
                    ];
                })->values();
            } else {
                $record->rx_items = $rxItems;
            }
            $record->prescriptions = $record->rx_items;

            // Diagnostic requests with fallback to legacy DocRequestsModel & DiagnosticsMasterlistModel
            $diagItems = $ledger->whereIn('item_grouping', ['DIAGNOSTIC', 'IMAGING'])->values()
                ->map(function ($diag) {
                    $diag = clone $diag;
                    $diag->exam_name = $diag->item_dscr;
                    $diag->category = $diag->item_grouping;
                    $diag->notes = $diag->remarks;
                    $diag->requested_at = $diag->updated;
                    return $diag;
                });

            if ($diagItems->isEmpty()) {
                $record->diagnostic_items = $legacyReqs->get($ref, collect())->map(function ($req) use ($masterDiagModels) {
                    $master = $masterDiagModels->get($req->requestrefno);
                    return (object)[
                        'prodcode' => $req->requestrefno,
                        'item_dscr' => $master->diagnostic_name ?? $req->request_dscr ?? 'Diagnostic Procedure',
                        'exam_name' => $master->diagnostic_name ?? $req->request_dscr ?? 'Diagnostic Procedure',
                        'item_grouping' => $master->diagnostic_catg ?? 'DIAGNOSTIC',
                        'category' => $master->diagnostic_catg ?? 'DIAGNOSTIC',
                        'remarks' => $req->request_dscr ?? '',
                        'notes' => $req->request_dscr ?? '',
                        'requested_at' => $req->created_at ?? $req->recorddate ?? '',
                        'updated' => $req->recorddate ?? '',
                    ];
                })->values();
            } else {
                $record->diagnostic_items = $diagItems;
            }
            $record->diagnostics = $record->diagnostic_items;

            // Charges from the ledger with fallback to legacy DocChargesModel
            $charges = $ledger->values()->map(function ($c) {
                $c = clone $c;
                $c->item_name = $c->item_dscr;
                $c->unit_price = $c->cost_ave ?: $c->retails;
                $c->category = $c->item_grouping;
                return $c;
            });

            if ($charges->isEmpty()) {
                $fallback = $legacyCharges->get($ref, collect())->map(function ($dc) {
                    $qty = $dc->quantity ?: ($dc->qty ?: 1);
                    $unit = $dc->charge ?: ($dc->rate ?: 0);
                    $total = $dc->net_total ?: ($dc->total ?: ($dc->totalamt ?? ($qty * $unit)));
                    $name = $dc->servicename ?: ($dc->chargedesc ?? 'Charge');
                    $code = $dc->servicerefno ?: ($dc->chargecode ?? 'CHARGE');
                    return (object)[
                        'id' => $dc->id,
                        'prodcode' => $code,
                        'item_dscr' => $name,
                        'item_name' => $name,
                        'item_grouping' => 'PROFESSIONAL FEE',
                        'category' => 'PROFESSIONAL FEE',
                        'qty' => $qty,
                        'cost_ave' => $unit,
                        'retails' => $unit,
                        'unit_price' => $unit,
                        'totalamt' => $total,
                    ];
                })->values();
                if ($fallback->isNotEmpty()) {
                    $charges = $fallback;
                }
            }

            $record->charges = $charges;
            $record->charges_total = round((float) $charges->sum('totalamt'), 2);

            return $record;
        });

        return response()->json([
            'draw' => intval($request->draw),
            'recordsFiltered' => $history->count(),
            'recordsTotal' => $history->count(),
            'success' => true,
            'history' => $history,
            'medhistory' => $history,
            'data' => $history,
            'permanent_medhistory' => $primaryMedHistory
        ]);
    }

    // Settlements-related
    public function fetchSettlements(Request $request)
    {
        $record = SettlementsModel::where(['consultationrefno' => $request->consultationrefno])->first();

        if ($record) {
            return response()->json(['success' => true, 'record' => $record]);
        }

        return response()->json(['success' => false]);
    }

    /**
     * Detailed Comment: Saves or updates patient consultation billing settlement details in pxsettlements.
     * Computes gross charges and categorized subtotals from stocks_ledger, applies Senior/PWD, PhilHealth (PHIC),
     * HMO and custom discounts, records cash and card payment channels, generates the transaction reference
     * number (TRX...) once, and logs structured audit events.
     * created/createdby are only set on creation so later edits do not overwrite the original audit data.
     */
    public function saveSettlements(Request $request)
    {
        $user = auth()->guard('secretary')->user() ?? auth()->guard('admin')->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }
        $actor = $this->currentActorName();

        $consultRef = $request->sett_consultationrefno ?: $request->consultationrefno;
        if (empty($consultRef)) {
            return response()->json(['success' => false, 'message' => 'Consultation reference number is required.'], 422);
        }

        $consultation = ConsultationModel::where('consultationrefno', $consultRef)->first();

        // Categorized charge totals from stocks_ledger for billing auditing and SOA breakdown
        $charges = StocksLedgerModel::where('px_consultcode_cn', $consultRef)->get();
        $knownGroups = ['DRUGS AND MEDS', 'DIAGNOSTIC', 'PROFESSIONAL FEE', 'PROCEDURES', 'SUPPLIES', 'VACCINES', 'IMMUNIZATION'];
        $totalMeds = (float) $charges->where('item_grouping', 'DRUGS AND MEDS')->sum('totalamt');
        $totalLab = (float) $charges->where('item_grouping', 'DIAGNOSTIC')->sum('totalamt');
        $totalPf = (float) $charges->where('item_grouping', 'PROFESSIONAL FEE')->sum('totalamt');
        $totalProcedures = (float) $charges->where('item_grouping', 'PROCEDURES')->sum('totalamt');
        $totalSupplies = (float) $charges->where('item_grouping', 'SUPPLIES')->sum('totalamt');
        $totalVaccines = (float) $charges->where('item_grouping', 'VACCINES')->sum('totalamt');
        $totalImmunizations = (float) $charges->where('item_grouping', 'IMMUNIZATION')->sum('totalamt');
        $totalOthers = (float) $charges->whereNotIn('item_grouping', $knownGroups)->sum('totalamt');

        $computedGross = (float) $charges->sum('totalamt');
        $totalGross = $computedGross > 0 ? $computedGross : (float) ($request->total ?? 0);

        // Deductions
        $lessSrpwd = (float) ($request->less_srpwd ?: 0);
        $srpwdRefNo = $request->srpwd_refno ?: null;
        $lessPhic = (float) ($request->phic ?: ($request->less_phic ?: 0));
        $phicIcdRvs = $request->phic_icd_rvs ?: null;
        $lessHmo = (float) ($request->hmo ?: ($request->less_hmo ?: 0));
        $hmoLoaNo = $request->hmo_loa_no ?: null;
        $lessDiscount = (float) ($request->less_discount ?: ($request->discount ?: 0));
        $discountDescription = $request->discount_description ?: null;
        $isYakap = ($request->boolean('is_philhealth_yakap') || $request->is_philhealth_yakap == '1') ? 1 : 0;
        $copay = (float) ($request->copay ?: 0);

        $netPayable = max(0, $totalGross - $lessSrpwd - $lessPhic - $lessHmo - $lessDiscount);

        // Resolve HMO code and name from authoritative "hmo_masterlist"
        $hmoInput = $request->hmo_type;
        $hmoCode = $hmoInput;
        $hmoName = null;
        if (!empty($hmoInput)) {
            $hmoRecord = HMOModel::where('hmocode', $hmoInput)->orWhere('hmoname', $hmoInput)->first();
            if ($hmoRecord) {
                $hmoCode = $hmoRecord->hmocode;
                $hmoName = $hmoRecord->hmoname;
            } else {
                $hmoName = $hmoInput;
            }
        }

        $values = [
            'pincode' => $consultation->pincode ?? $request->pincode ?? null,
            'docrefno' => $consultation->docrefno ?? null,
            'docname' => $consultation->docname ?? null,
            'total_gross' => $totalGross,
            'net_payable' => $netPayable,
            'total_meds' => $totalMeds,
            'total_lab' => $totalLab,
            'total_doctorspf' => $totalPf,
            'total_procedures' => $totalProcedures,
            'total_supplies' => $totalSupplies,
            'total_vaccines' => $totalVaccines,
            'total_immunizations' => $totalImmunizations,
            'total_others' => $totalOthers,
            'less_srpwd' => $lessSrpwd,
            'srpwd_refno' => $srpwdRefNo,
            'less_phic' => $lessPhic,
            'phic_icd_rvs' => $phicIcdRvs,
            'less_hmo' => $lessHmo,
            'hmo_type' => $hmoName ?: $hmoCode,
            'hmocode' => $hmoCode,
            'hmo_loa_no' => $hmoLoaNo,
            'hmoname' => $hmoName ?: $hmoCode,
            'less_discount' => $lessDiscount,
            'discount_description' => $discountDescription,
            'payment_cash' => (float) ($request->cash ?: 0),
            'is_philhealth_yakap' => $isYakap,
            'copay' => $copay,
            'payment_card' => (float) ($request->cta ?: 0),
            'cta_type' => $request->cta_type ?? $request->card_type,
        ];

        $record = SettlementsModel::updateOrCreate(['consultationrefno' => $consultRef], $values);

        if ($record->wasRecentlyCreated) {
            $record->created = Date::now();
            $record->createdby = $actor;
        }
        if (empty($record->transactionrefno)) {
            $record->transactionrefno = 'TRX' . Date::now()->format('mdYHis');
        }
        $record->save();

        Log::info('Consultation settlement saved', [
            'consultationrefno' => $consultRef,
            'total_gross' => $totalGross,
            'less_srpwd' => $lessSrpwd,
            'srpwd_refno' => $srpwdRefNo,
            'less_phic' => $lessPhic,
            'phic_icd_rvs' => $phicIcdRvs,
            'less_hmo' => $lessHmo,
            'hmocode' => $hmoCode,
            'hmo_loa_no' => $hmoLoaNo,
            'hmoname' => $hmoName,
            'less_discount' => $lessDiscount,
            'discount_description' => $discountDescription,
            'is_philhealth_yakap' => $isYakap,
            'copay' => $copay,
            'net_payable' => $netPayable,
            'transactionrefno' => $record->transactionrefno,
            'recorded_by' => $actor,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Detailed Comment: Fetches available HMO entities from the authoritative "hmo_masterlist" table
     * for the queue settlement modal. Filters out null/empty names and orders alphabetically.
     */
    public function fetchHmo()
    {
        $clientCode = session()->get('clientcode') ?? config('app.client_code', '122377');

        $query = HMOModel::select(['hmocode', 'hmoname'])
            ->whereNotNull('hmoname')
            ->where('hmoname', '!=', '')
            ->orderBy('hmoname', 'ASC');

        if ($clientCode) {
            $hmo = (clone $query)->where(function ($q) use ($clientCode) {
                $q->where('dw_clientcode', $clientCode)
                    ->orWhereNull('dw_clientcode')
                    ->orWhere('dw_clientcode', '');
            })->get();
            if ($hmo->isNotEmpty()) {
                return response()->json(['hmo' => $hmo]);
            }
        }

        $hmo = $query->get();
        return response()->json(['hmo' => $hmo]);
    }

    /**
     * Detailed Comment: Fetches payment and settlement history for a patient based on consultation history.
     * Payment status counts cash + card + copay, consistent with the daily financial summary.
     */
    public function fetchPatientPaymentHistory(Request $request)
    {
        foreach (['pincode', 'pxrefno', 'consultationrefno'] as $key) {
            if (in_array($request->input($key), ['undefined', 'null'], true)) {
                $request->merge([$key => null]);
            }
        }

        $pincode = $request->input('pincode');
        $pxrefno = $request->input('pxrefno');
        $consultationrefno = $request->input('consultationrefno');

        $query = SettlementsModel::query();

        if ($pxrefno || $pincode) {
            $consulRefs = ConsultationModel::where(function ($q) use ($pxrefno, $pincode) {
                if ($pxrefno) $q->where('pxrefno', $pxrefno);
                if ($pincode) $q->orWhere('pincode', $pincode);
            })->pluck('consultationrefno')->filter()->toArray();

            $query->where(function ($q) use ($consulRefs, $pincode) {
                if (!empty($consulRefs)) {
                    $q->whereIn('consultationrefno', $consulRefs);
                }
                if ($pincode) {
                    $q->orWhere('pincode', $pincode);
                }
            });
        } elseif ($consultationrefno) {
            $query->where('consultationrefno', $consultationrefno);
        } else {
            return response()->json(['success' => true, 'payments' => []]);
        }

        $payments = $query->orderBy('id', 'desc')->get();

        $consultations = ConsultationModel::whereIn('consultationrefno', $payments->pluck('consultationrefno')->filter()->toArray())
            ->pluck('consultation_date', 'consultationrefno');

        $payments->transform(function ($payment) use ($consultations) {
            $rawDate = $consultations[$payment->consultationrefno] ?? $payment->created;
            $payment->consultation_date = $rawDate ? date('Y-m-d', strtotime($rawDate)) : 'N/A';
            $totalPaid = (float)($payment->payment_cash ?? 0)
                + (float)($payment->payment_card ?? 0)
                + (float)($payment->copay ?? 0);
            $net = (float)($payment->net_payable ?? $payment->total_gross ?? 0);
            $payment->payment_status = ($totalPaid >= $net && $net > 0) ? 'PAID' : (($totalPaid > 0) ? 'PARTIAL' : 'UNPAID');
            return $payment;
        });

        return response()->json([
            'success' => true,
            'payments' => $payments
        ]);
    }

    /**
     * Detailed Comment: Fetches permanent medical history records for a patient,
     * cross-referencing pxrefno and pincode across masterlist and active consultation.
     */
    public function fetchPatientMedicalHistory(Request $request)
    {
        $pxrefno = $request->input('pxrefno');
        $pincode = $request->input('pincode');
        $consultationrefno = $request->input('consultationrefno');

        if ($pxrefno === 'undefined' || $pxrefno === 'null') $pxrefno = null;
        if ($pincode === 'undefined' || $pincode === 'null') $pincode = null;
        if ($consultationrefno === 'undefined' || $consultationrefno === 'null') $consultationrefno = null;

        if (empty($pxrefno) && !empty($consultationrefno)) {
            $consult = ConsultationModel::where('consultationrefno', $consultationrefno)->first();
            if ($consult) {
                $pxrefno = $consult->pxrefno;
                if (empty($pincode)) $pincode = $consult->pincode;

                if (empty($pxrefno) && !empty($consult->patientname) && !empty($consult->birthday)) {
                    $pMaster = PatientMasterlist::where('patientname', $consult->patientname)
                        ->where('birthday', $consult->birthday)
                        ->first();
                    if ($pMaster) {
                        $pxrefno = $pMaster->pxrefno;
                        if (empty($pincode)) $pincode = $pMaster->pincode;
                    }
                }
            }
        }

        if (empty($pxrefno) && !empty($pincode)) {
            $pMaster = PatientMasterlist::where('pincode', $pincode)->first();
            if ($pMaster) {
                $pxrefno = $pMaster->pxrefno;
            }
        }

        $medHistory = null;
        if (!empty($pxrefno) || !empty($pincode)) {
            $medHistory = PxMedicalHistoryModel::where(function ($q) use ($pxrefno, $pincode) {
                if (!empty($pxrefno)) $q->where('pxrefno', $pxrefno);
                if (!empty($pincode)) $q->orWhere('pincode', $pincode);
            })->first();
        }

        return response()->json([
            'success' => true,
            'pxrefno' => $pxrefno,
            'pincode' => $pincode,
            'data' => $medHistory,
            'permanent_medhistory' => $medHistory
        ]);
    }

    /**
     * Detailed Comment: Creates or updates permanent medical history records for a patient.
     * Only fields actually sent by the client are written, so omitted fields no longer wipe existing data.
     */
    public function savePatientMedicalHistory(Request $request)
    {
        $pxrefno = $request->input('pxrefno');
        $pincode = $request->input('pincode');
        $consultationrefno = $request->input('consultationrefno');

        if ($pxrefno === 'undefined' || $pxrefno === 'null') $pxrefno = null;
        if ($pincode === 'undefined' || $pincode === 'null') $pincode = null;
        if ($consultationrefno === 'undefined' || $consultationrefno === 'null') $consultationrefno = null;

        if (empty($pxrefno) && !empty($consultationrefno)) {
            $consult = ConsultationModel::where('consultationrefno', $consultationrefno)->first();
            if ($consult) {
                $pxrefno = $consult->pxrefno;
                if (empty($pincode)) $pincode = $consult->pincode;

                if (empty($pxrefno) && !empty($consult->patientname) && !empty($consult->birthday)) {
                    $pMaster = PatientMasterlist::where('patientname', $consult->patientname)
                        ->where('birthday', $consult->birthday)
                        ->first();
                    if ($pMaster) {
                        $pxrefno = $pMaster->pxrefno;
                        if (empty($pincode)) $pincode = $pMaster->pincode;
                    }
                }
            }
        }

        if (empty($pxrefno) && !empty($pincode)) {
            $pMaster = PatientMasterlist::where('pincode', $pincode)->first();
            if ($pMaster) {
                $pxrefno = $pMaster->pxrefno;
            }
        }

        if (empty($pxrefno) && empty($pincode)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot save medical history: Patient reference number (pxrefno) or PIN code is required.'
            ], 422);
        }

        // Current user across secretary, doctor, or admin guards
        $userDisplay = 'System';
        if (auth()->guard('secretary')->check()) {
            $sec = auth()->guard('secretary')->user();
            $userDisplay = trim(($sec->secfname ?? '') . ' ' . ($sec->seclname ?? '')) ?: ($sec->secname ?? 'Secretary');
        } elseif (auth()->guard('doctor')->check()) {
            $doc = auth()->guard('doctor')->user();
            $userDisplay = 'Dr. ' . ($doc->docname ?? 'Physician');
        } elseif (auth()->guard('admin')->check()) {
            $userDisplay = auth()->guard('admin')->user()->name ?? 'Administrator';
        }

        $existing = PxMedicalHistoryModel::where(function ($q) use ($pxrefno, $pincode) {
            if (!empty($pxrefno)) $q->where('pxrefno', $pxrefno);
            if (!empty($pincode)) $q->orWhere('pincode', $pincode);
        })->first();

        $data = [
            'pxrefno' => $pxrefno ?: ($existing->pxrefno ?? null),
            'pincode' => $pincode ?: ($existing->pincode ?? null),
            'updatedby' => $userDisplay,
        ];

        $fields = [
            'allergies',
            'injections_immunization',
            'past_medical_history',
            'surgical_history',
            'family_history',
            'maintenance_medications',
            'notes',
        ];
        foreach ($fields as $field) {
            if ($request->has($field)) {
                $data[$field] = $request->input($field);
            }
        }

        if (!$existing) {
            $data['recordedby'] = $userDisplay;
            $record = PxMedicalHistoryModel::create($data);
        } else {
            $existing->update($data);
            $record = $existing;
        }

        Log::info('Saved patient permanent medical history', [
            'pxrefno' => $pxrefno,
            'pincode' => $pincode,
            'updatedby' => $userDisplay,
            'has_allergies' => !empty($request->input('allergies')),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Patient medical history successfully saved.',
            'data' => $record
        ]);
    }

    /**
     * Detailed Comment: Fetches PhilHealth Yakap Medical & Surgical History for the active patient.
     * Searches dd_profile_1, dd_profile_medhist, dd_profile_mhspecific and dd_profile_surghist,
     * resolving links via px_pin, pincode, pxrefno, or consultation case number.
     */
    public function fetchPatientYakapMedicalHistory(Request $request)
    {
        $pxrefno = $request->input('pxrefno');
        $pincode = $request->input('pincode');
        $caseno = $request->input('caseno');
        $consultationrefno = $request->input('consultationrefno');

        if ($pxrefno === 'undefined' || $pxrefno === 'null') $pxrefno = null;
        if ($pincode === 'undefined' || $pincode === 'null') $pincode = null;
        if ($caseno === 'undefined' || $caseno === 'null') $caseno = null;
        if ($consultationrefno === 'undefined' || $consultationrefno === 'null') $consultationrefno = null;

        if (!empty($consultationrefno)) {
            $consult = ConsultationModel::where('consultationrefno', $consultationrefno)->first();
            if ($consult) {
                if (empty($pxrefno)) $pxrefno = $consult->pxrefno;
                if (empty($pincode)) $pincode = $consult->pincode ?: $consult->phic_pin;
                if (empty($caseno)) $caseno = $consult->caseno ?: $consult->en_caseno;
            }
        }

        if (empty($pxrefno) && !empty($pincode)) {
            $pMaster = PatientMasterlist::where('pincode', $pincode)->first();
            if ($pMaster) {
                $pxrefno = $pMaster->pxrefno;
            }
        } elseif (!empty($pxrefno) && empty($pincode)) {
            $pMaster = PatientMasterlist::where('pxrefno', $pxrefno)->first();
            if ($pMaster) {
                $pincode = $pMaster->pincode;
            }
        }

        // Locate existing Yakap Profile in dd_profile_1
        $profile = null;
        if (!empty($caseno)) {
            $profile = ProfileModel::where('en_CaseNo', $caseno)->first();
        }
        if (!$profile && !empty($pincode)) {
            $profile = ProfileModel::where('dPatientPin', $pincode)
                ->orWhere('px_pin', $pincode)
                ->first();
        }
        if (!$profile && !empty($pxrefno)) {
            $profile = ProfileModel::where('px_pin', $pxrefno)->first();
        }

        $transNo = $profile ? $profile->dTransNo : null;
        $pinToMatch = $profile ? ($profile->px_pin ?: $profile->dPatientPin) : ($pincode ?: $pxrefno);

        $scope = function ($query) use ($transNo, $pinToMatch) {
            if ($transNo) {
                $query->where('p_TransNo', $transNo);
            } elseif (!empty($pinToMatch)) {
                $query->where('px_pin', $pinToMatch);
            } else {
                $query->whereRaw('1 = 0');
            }
            return $query;
        };

        $medHist = $scope(ProfileMedHistModel::query())->get();
        $mhSpecific = $scope(ProfileMhSpecificModel::query())->get();
        $surgHist = $scope(ProfileSurgHistModel::query())->get();

        Log::info('Fetched Yakap Medical & Surgical History for patient', [
            'pxrefno' => $pxrefno,
            'pincode' => $pincode,
            'caseno' => $caseno,
            'transNo' => $transNo,
            'conditions_count' => $medHist->count(),
            'surgeries_count' => $surgHist->count(),
        ]);

        return response()->json([
            'success' => true,
            'profile' => $profile,
            'transNo' => $transNo,
            'medHist' => $medHist,
            'mhSpecific' => $mhSpecific,
            'surgHist' => $surgHist,
        ]);
    }

    /**
     * Detailed Comment: Persists PhilHealth Yakap Medical & Surgical History from Secretary Console.
     * Updates dd_profile_medhist, dd_profile_mhspecific and dd_profile_surghist inside a transaction,
     * creating a profile header record in dd_profile_1 if none exists.
     */
    public function savePatientYakapMedicalHistory(Request $request)
    {
        $pxrefno = $request->input('pxrefno');
        $pincode = $request->input('pincode');
        $caseno = $request->input('caseno');
        $consultationrefno = $request->input('consultationrefno');
        $patientname = $request->input('patientname');
        $transNo = $request->input('transNo');

        if ($pxrefno === 'undefined' || $pxrefno === 'null') $pxrefno = null;
        if ($pincode === 'undefined' || $pincode === 'null') $pincode = null;
        if ($caseno === 'undefined' || $caseno === 'null') $caseno = null;
        if ($consultationrefno === 'undefined' || $consultationrefno === 'null') $consultationrefno = null;
        if ($transNo === 'undefined' || $transNo === 'null') $transNo = null;

        if (!empty($consultationrefno)) {
            $consult = ConsultationModel::where('consultationrefno', $consultationrefno)->first();
            if ($consult) {
                if (empty($pxrefno)) $pxrefno = $consult->pxrefno;
                if (empty($pincode)) $pincode = $consult->pincode ?: $consult->phic_pin;
                if (empty($caseno)) $caseno = $consult->caseno ?: $consult->en_caseno;
                if (empty($patientname)) $patientname = $consult->patientname;
            }
        }

        if (empty($pxrefno) && !empty($pincode)) {
            $pMaster = PatientMasterlist::where('pincode', $pincode)->first();
            if ($pMaster) {
                $pxrefno = $pMaster->pxrefno;
                if (empty($patientname)) $patientname = $pMaster->patientname;
            }
        }

        if (empty($pxrefno) && empty($pincode)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot save Yakap medical history: Patient reference number or PIN is required.'
            ], 422);
        }

        $userDisplay = 'System';
        if (auth()->guard('secretary')->check()) {
            $sec = auth()->guard('secretary')->user();
            $userDisplay = trim(($sec->secfname ?? '') . ' ' . ($sec->seclname ?? '')) ?: ($sec->secname ?? 'Secretary');
        } elseif (auth()->guard('admin')->check()) {
            $userDisplay = auth()->guard('admin')->user()->name ?? 'Administrator';
        }

        /** @var ClientService $clientService */
        $clientService = app(ClientService::class);
        $clientCode = $clientService->getClientCode() ?: '122377';

        try {
            DB::beginTransaction();

            // Locate or generate Yakap profile record
            $profile = null;
            if (!empty($transNo)) {
                $profile = ProfileModel::where('dTransNo', $transNo)->first();
            }
            if (!$profile && !empty($caseno)) {
                $profile = ProfileModel::where('en_CaseNo', $caseno)->first();
            }
            if (!$profile && !empty($pincode)) {
                $profile = ProfileModel::where('dPatientPin', $pincode)
                    ->orWhere('px_pin', $pincode)
                    ->first();
            }
            if (!$profile && !empty($pxrefno)) {
                $profile = ProfileModel::where('px_pin', $pxrefno)->first();
            }

            if ($profile) {
                $transNo = $profile->dTransNo;
                $profileCaseNo = $profile->en_CaseNo ?: ($caseno ?: 'C' . now()->format('YmdHis'));
                $pinCodeToUse = $profile->px_pin ?: ($pincode ?: $pxrefno);
            } else {
                $accreNo = rescue(fn() => $clientService->getHciAccreditationNumber(), 'H10037034') ?: 'H10037034';

                // Generate a unique Yakap profile transaction number (re-roll on collision)
                do {
                    $transNo = 'P' . $accreNo . now()->format('Ym') . random_int(10000, 99999);
                } while (ProfileModel::where('dTransNo', $transNo)->exists());

                $profileCaseNo = $caseno ?: ('C' . now()->format('YmdHis'));
                $pinCodeToUse = $pincode ?: $pxrefno;

                $profile = ProfileModel::create([
                    'dw_clientcode' => $clientCode,
                    'dTransNo' => $transNo,
                    'en_CaseNo' => $profileCaseNo,
                    'px_pin' => $pinCodeToUse,
                    'dProfDate' => now()->toDateString(),
                    'dPatientPin' => $pincode ?: $pinCodeToUse,
                    'patientname' => $patientname ?: 'Walk-in Patient',
                    'dPatientType' => 'MM',
                    'dPatientAge' => '',
                    'dMemPin' => $pincode ?: $pinCodeToUse,
                    'dEffyear' => now()->format('Y'),
                    'dATC' => '',
                    'dIsWalkedIn' => 'Y',
                    'dTransDate' => now()->toDateString(),
                    'dReportStatus' => 'U',
                ]);
            }

            // Past Medical Conditions
            $chkMedHistDiseases = $request->input('chkMedHistDiseases', []);
            $medhistToSave = [];
            $mhspecificToSave = [];

            if (is_array($chkMedHistDiseases)) {
                foreach ($chkMedHistDiseases as $diseaseCode => $condition) {
                    if (isset($condition['selected']) && !empty($condition['selected'])) {
                        $medhistToSave[] = [
                            'dw_clientcode' => $clientCode,
                            'px_pin' => $pinCodeToUse,
                            'p_TransNo' => $transNo,
                            'en_caseno' => $profileCaseNo,
                            'dMdiseaseCode' => (string)$diseaseCode,
                            'dReportStatus' => 'U',
                            'dDeficiencyRemarks' => '',
                            'createdby' => $userDisplay,
                            'created' => now(),
                            'updatedby' => $userDisplay,
                            'updated' => now(),
                        ];

                        if (isset($condition['specify']) && trim($condition['specify']) !== '') {
                            $mhspecificToSave[] = [
                                'dw_clientcode' => $clientCode,
                                'px_pin' => $pinCodeToUse,
                                'en_caseno' => $profileCaseNo,
                                'p_TransNo' => $transNo,
                                'dMdiseaseCode' => (string)$diseaseCode,
                                'dSpecificDesc' => trim($condition['specify']),
                                'dReportStatus' => 'U',
                                'dDeficiencyRemarks' => '',
                                'created' => now(),
                                'createdby' => $userDisplay,
                                'updated' => now(),
                                'updatedby' => $userDisplay,
                            ];
                        }
                    }
                }
            }

            ProfileMedHistModel::where('p_TransNo', $transNo)->delete();
            ProfileMhSpecificModel::where('p_TransNo', $transNo)->delete();

            if (!empty($medhistToSave)) {
                ProfileMedHistModel::insert($medhistToSave);
            }
            if (!empty($mhspecificToSave)) {
                ProfileMhSpecificModel::insert($mhspecificToSave);
            }

            // Past Surgical Procedures
            $surgicalHistory = $request->input('surgicalHistory', []);
            $surgHistToSave = [];

            if (is_array($surgicalHistory)) {
                foreach ($surgicalHistory as $surg) {
                    $op = isset($surg['operation']) ? trim($surg['operation']) : '';
                    $date = isset($surg['date']) ? trim($surg['date']) : '';
                    if (!empty($op)) {
                        $surgHistToSave[] = [
                            'dw_clientcode' => $clientCode,
                            'px_pin' => $pinCodeToUse,
                            'p_TransNo' => $transNo,
                            'en_caseno' => $profileCaseNo,
                            'dSurgDesc' => $op,
                            'dSurgDate' => !empty($date) ? $date : null,
                            'dReportStatus' => 'U',
                            'dDeficiencyRemarks' => '',
                            'createdby' => $userDisplay,
                            'created' => now(),
                            'updatedby' => $userDisplay,
                            'updated' => now(),
                        ];
                    }
                }
            }

            ProfileSurgHistModel::where('p_TransNo', $transNo)->delete();
            if (!empty($surgHistToSave)) {
                ProfileSurgHistModel::insert($surgHistToSave);
            }

            DB::commit();

            Log::info('Successfully saved Yakap Medical & Surgical History', [
                'transNo' => $transNo,
                'px_pin' => $pinCodeToUse,
                'conditions_saved' => count($medhistToSave),
                'surgeries_saved' => count($surgHistToSave),
                'saved_by' => $userDisplay,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Yakap Medical History and Profiling successfully saved.',
                'transNo' => $transNo,
                'profile' => $profile,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error saving Yakap Medical History', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while saving Yakap Medical History: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Detailed Comment: Shared builder for the daily queue financial summary, used by both the JSON endpoint
     * and the PDF report so the two can never drift apart again (the PDF previously read a non-existent
     * discount_senior column and always showed 0 for Senior/PWD).
     * Returns [queueDate, consultations, settlements, totals, items].
     */
    private function buildFinancialSummary(Request $request): array
    {
        $queueDate = $request->input('queuedate') ?: $request->input('date') ?: now()->toDateString();
        $docrefno = $request->input('docrefno');

        $query = ConsultationModel::whereDate('consultation_date', $queueDate);
        if (!empty($docrefno) && !in_array($docrefno, ['null', 'all'], true)) {
            $query->where('docrefno', $docrefno);
        }

        $consultations = $query->orderBy('queueno', 'asc')->orderBy('id', 'asc')->get();
        $consultationRefNos = $consultations->pluck('consultationrefno')->filter()->values()->toArray();

        $settlements = SettlementsModel::whereIn('consultationrefno', $consultationRefNos)
            ->get()
            ->keyBy('consultationrefno');

        $totals = array_fill_keys(
            ['gross', 'phic', 'hmo', 'senior', 'net', 'cash', 'card', 'copay', 'paid', 'balance'],
            0.0
        );
        $items = [];

        foreach ($consultations as $consult) {
            $settle = $settlements->get($consult->consultationrefno);

            $gross = (float)($settle->total_gross ?? $settle->total_amount ?? 0);
            $phic = (float)($settle->less_phic ?? 0);
            $hmo = (float)($settle->less_hmo ?? 0);
            $senior = (float)($settle->less_srpwd ?? $settle->discount_senior ?? 0);
            $net = (float)($settle->net_payable ?? $settle->net_billing ?? 0);
            $cash = (float)($settle->payment_cash ?? 0);
            $card = (float)($settle->payment_card ?? 0);
            $copay = (float)($settle->copay ?? 0);
            $paid = $cash + $card + $copay;
            $balance = max(0, $net - $paid);

            foreach (compact('gross', 'phic', 'hmo', 'senior', 'net', 'cash', 'card', 'copay', 'paid', 'balance') as $key => $value) {
                $totals[$key] += $value;
            }

            $status = 'UNPAID';
            if ($settle) {
                if ($paid >= $net && $net > 0) {
                    $status = 'PAID';
                } elseif ($paid > 0) {
                    $status = 'PARTIAL';
                }
            }

            $items[] = [
                'queueno' => $consult->queueno ?: '--',
                'consultationrefno' => $consult->consultationrefno,
                'patientname' => $consult->patientname,
                'docname' => $consult->docname,
                'gross' => $gross,
                'phic' => $phic,
                'hmo' => $hmo,
                'senior' => $senior,
                'net' => $net,
                'cash' => $cash,
                'card' => $card,
                'copay' => $copay,
                'paid' => $paid,
                'balance' => $balance,
                'status' => $status,
                'is_philhealth_yakap' => (bool)($settle->is_philhealth_yakap ?? false),
                'hmo_loa_no' => $settle->hmo_loa_no ?? '',
            ];
        }

        return [$queueDate, $consultations, $settlements, $totals, $items];
    }

    /**
     * Detailed Comment: Computes aggregated daily income financial report for all patients in queue
     * (gross billing, PhilHealth, HMO, Senior/PWD, net, cash, card/CTA, Yakap co-pay, collected, balance).
     */
    public function fetchQueueFinancialSummary(Request $request)
    {
        [$queueDate, $consultations, $settlements, $t, $items] = $this->buildFinancialSummary($request);

        return response()->json([
            'success' => true,
            'queuedate' => $queueDate,
            'date' => $queueDate,
            'total_patients' => $consultations->count(),
            'settled_patients' => $settlements->count(),
            'summary' => [
                'total_patients' => $consultations->count(),
                'total_gross' => round($t['gross'], 2),
                'gross_total' => round($t['gross'], 2),
                'total_phic' => round($t['phic'], 2),
                'total_hmo' => round($t['hmo'], 2),
                'total_senior' => round($t['senior'], 2),
                'total_srpwd' => round($t['senior'], 2),
                'total_net' => round($t['net'], 2),
                'total_cash' => round($t['cash'], 2),
                'total_card' => round($t['card'], 2),
                'total_copay' => round($t['copay'], 2),
                'total_paid' => round($t['paid'], 2),
                'total_balance' => round($t['balance'], 2),
            ],
            'items' => $items,
        ]);
    }

    /**
     * Detailed Comment: Streams PDF printable report for the daily queue financial summary.
     */
    public function printFinancialReport(Request $request)
    {
        [$queueDate, $consultations,, $t, $itemRows] = $this->buildFinancialSummary($request);

        $items = array_map(fn($row) => (object)$row, $itemRows);

        $doctorName = 'All Attending Doctors';
        $docrefno = $request->input('docrefno');
        if (!empty($docrefno) && !in_array($docrefno, ['null', 'all'], true)) {
            $doc = DoctorsProfileModel::where('docrefno', $docrefno)->first();
            if ($doc) {
                $doctorName = 'Dr. ' . $doc->docname;
            }
        }

        $summary = (object)[
            'total_gross' => $t['gross'],
            'total_phic' => $t['phic'],
            'total_hmo' => $t['hmo'],
            'total_senior' => $t['senior'],
            'total_net' => $t['net'],
            'total_cash' => $t['cash'],
            'total_card' => $t['card'],
            'total_copay' => $t['copay'],
            'total_paid' => $t['paid'],
            'total_balance' => $t['balance'],
            'total_patients' => $consultations->count(),
        ];

        $profile = KayakapProfileModel::first() ?? (object)[
            'HOSP_NAME' => config('app.name', 'KayakapMD Clinic'),
            'HOSP_ADDBRGY' => ''
        ];

        $generatedBy = 'Secretary';
        if (auth()->guard('secretary')->check()) {
            $sec = auth()->guard('secretary')->user();
            $generatedBy = trim(($sec->secfname ?? '') . ' ' . ($sec->seclname ?? '')) ?: 'Secretary';
        }

        try {
            return Pdf::loadView('printables.financial_report_print', compact('summary', 'items', 'queueDate', 'doctorName', 'profile', 'generatedBy'))
                ->setPaper('A4', 'landscape')
                ->stream('daily_financial_report_' . $queueDate . '.pdf');
        } catch (\Throwable $e) {
            Log::error('Failed to generate daily financial report PDF', [
                'queuedate' => $queueDate,
                'error' => $e->getMessage(),
            ]);

            return response('Error generating financial report PDF: ' . $e->getMessage(), 500)
                ->header('Content-Type', 'text/plain');
        }
    }
}
