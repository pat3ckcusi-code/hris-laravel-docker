@extends('dashboards.layout', [
    'title' => 'Personal Data Sheet (PDS)',
    'subtitle' => 'CS Form No. 212 (Revised 2025) employee data capture form.',
])

@section('content')
    <form class="pds-form" aria-label="CS Form No. 212 Revised 2025 Personal Data Sheet">
        @csrf
        <section class="pds-head">
            <div>
                <p class="pds-kicker">Republic of the Philippines</p>
                <h2>CS Form No. 212 - Personal Data Sheet</h2>
                <p class="pds-meta">Revised 2025</p>
            </div>
            <div class="pds-note">
                Fill out all required fields completely and accurately. Use N/A when a field is not applicable.
            </div>
        </section>

        <section class="pds-section pds-nav" aria-label="PDS section navigation">
            <div class="pds-nav-buttons" role="tablist" aria-label="PDS sections">
                <button type="button" class="pds-nav-btn active" data-target="pds-personal-info">I. Personal Info</button>
                <button type="button" class="pds-nav-btn" data-target="pds-family-background">II. Family</button>
                <button type="button" class="pds-nav-btn" data-target="pds-education">III. Education</button>
                <button type="button" class="pds-nav-btn" data-target="pds-eligibility">IV. Eligibility</button>
                <button type="button" class="pds-nav-btn" data-target="pds-work-experience">V. Work</button>
                <button type="button" class="pds-nav-btn" data-target="pds-voluntary-work">VI. Voluntary</button>
                <button type="button" class="pds-nav-btn" data-target="pds-learning-dev">VII. L&D</button>
                <button type="button" class="pds-nav-btn" data-target="pds-other-info">VIII. Other</button>
                <button type="button" class="pds-nav-btn" data-target="pds-additional-questions">IX. Questions</button>
                <button type="button" class="pds-nav-btn" data-target="pds-references">X. References</button>
                <button type="button" class="pds-nav-btn" data-target="pds-declaration">XI. Declaration</button>
            </div>

            <label class="pds-nav-mobile-label" for="pds-section-picker">Go to section</label>
            <select id="pds-section-picker" class="pds-nav-select" aria-label="Choose PDS section">
                <option value="pds-personal-info">I. Personal Information</option>
                <option value="pds-family-background">II. Family Background</option>
                <option value="pds-education">III. Educational Background</option>
                <option value="pds-eligibility">IV. Civil Service Eligibility</option>
                <option value="pds-work-experience">V. Work Experience</option>
                <option value="pds-voluntary-work">VI. Voluntary Work</option>
                <option value="pds-learning-dev">VII. Learning and Development</option>
                <option value="pds-other-info">VIII. Other Information</option>
                <option value="pds-additional-questions">IX. Additional Questions</option>
                <option value="pds-references">X. References</option>
                <option value="pds-declaration">XI. Declaration</option>
            </select>
        </section>

        <section class="pds-section pds-pane active" aria-labelledby="pds-personal-info" data-section="pds-personal-info">
            <h3 id="pds-personal-info">I. Personal Information</h3>

            <div class="field-grid four">
                <label>
                    Surname
                    <input type="text" name="personal[surname]" value="{{ $user->last_name ?? '' }}">
                </label>
                <label>
                    First Name
                    <input type="text" name="personal[first_name]" value="{{ $user->first_name ?? '' }}">
                </label>
                <label>
                    Middle Name
                    <input type="text" name="personal[middle_name]" value="{{ $user->middle_name ?? '' }}">
                </label>
                <label>
                    Name Extension (JR/SR/III)
                    <input type="text" name="personal[name_extension]">
                </label>
            </div>

            <div class="field-grid four">
                <label>
                    Date of Birth
                    <input type="date" name="personal[birth_date]">
                </label>
                <label>
                    Place of Birth
                    <input type="text" name="personal[birth_place]">
                </label>
                <label>
                    Sex
                    <select name="personal[sex]">
                        <option value="">Select</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </label>
                <label>
                    Civil Status
                    <select name="personal[civil_status]">
                        <option value="">Select</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                        <option value="Other">Other</option>
                    </select>
                </label>
            </div>

            <div class="field-grid six">
                <label>
                    Height (m)
                    <input type="text" name="personal[height]" placeholder="e.g., 1.70">
                </label>
                <label>
                    Weight (kg)
                    <input type="text" name="personal[weight]" placeholder="e.g., 65">
                </label>
                <label>
                    Blood Type
                    <input type="text" name="personal[blood_type]" placeholder="e.g., O+">
                </label>
                <label>
                    GSIS ID No.
                    <input type="text" name="personal[gsis_no]" data-no-uppercase>
                </label>
                <label>
                    PAG-IBIG ID No.
                    <input type="text" name="personal[pagibig_no]" data-no-uppercase>
                </label>
                <label>
                    PhilHealth No.
                    <input type="text" name="personal[philhealth_no]" data-no-uppercase>
                </label>
            </div>

            <div class="field-grid four">
                <label>
                    PhilSys Number (PSN):
                    <input type="text" name="personal[psn_no]" data-no-uppercase>
                </label>
                <label>
                    TIN No.
                    <input type="text" name="personal[tin_no]" data-no-uppercase>
                </label>
                <label>
                    Agency Employee No.
                    <input type="text" name="personal[agency_employee_no]" value="{{ $user->EmpNo ?? '' }}" data-no-uppercase>
                </label>
                <label>
                    Citizenship
                    <select name="personal[citizenship]">
                        <option value="">Select</option>
                        <option value="Filipino">Filipino</option>
                        <option value="Dual Citizenship">Dual Citizenship</option>
                    </select>
                </label>
            </div>

            <div class="field-grid two">
                <label>
                    If dual citizenship, indicate country
                    <input type="text" name="personal[dual_country]">
                </label>
                <label>
                    Acquisition Mode
                    <select name="personal[dual_acquisition]">
                        <option value="">Select</option>
                        <option value="By birth">By birth</option>
                        <option value="By naturalization">By naturalization</option>
                    </select>
                </label>
            </div>

            <div class="address-grid">
                <fieldset>
                    <legend>Residential Address</legend>
                    <div class="field-grid three">
                        <label>House/Block/Lot No.<input type="text" name="residential[house]"></label>
                        <label>Street<input type="text" name="residential[street]"></label>
                        <label>Subdivision/Village<input type="text" name="residential[subdivision]"></label>
                    </div>
                    <div class="field-grid three">
                        <label>Barangay<input type="text" name="residential[barangay]"></label>
                        <label>City/Municipality<input type="text" name="residential[city]"></label>
                        <label>Province<input type="text" name="residential[province]"></label>
                    </div>
                    <div class="field-grid two">
                        <label>ZIP Code<input type="text" name="residential[zip]" data-no-uppercase></label>
                        <label>Telephone No.<input type="text" name="residential[telephone]" data-no-uppercase></label>
                    </div>
                </fieldset>

                <fieldset>
                    <legend>Permanent Address</legend>
                    <div class="field-grid three">
                        <label>House/Block/Lot No.<input type="text" name="permanent[house]"></label>
                        <label>Street<input type="text" name="permanent[street]"></label>
                        <label>Subdivision/Village<input type="text" name="permanent[subdivision]"></label>
                    </div>
                    <div class="field-grid three">
                        <label>Barangay<input type="text" name="permanent[barangay]"></label>
                        <label>City/Municipality<input type="text" name="permanent[city]"></label>
                        <label>Province<input type="text" name="permanent[province]"></label>
                    </div>
                    <div class="field-grid two">
                        <label>ZIP Code<input type="text" name="permanent[zip]" data-no-uppercase></label>
                        <label>Telephone No.<input type="text" name="permanent[telephone]" data-no-uppercase></label>
                    </div>
                </fieldset>
            </div>

            <div class="field-grid two">
                <label>
                    Mobile No.
                    <input type="text" name="personal[mobile]" data-no-uppercase>
                </label>
                <label>
                    Email Address
                    <input type="email" name="personal[email]" value="{{ $user->email ?? '' }}">
                </label>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-family-background" data-section="pds-family-background">
            <h3 id="pds-family-background">II. Family Background</h3>

            <div class="field-grid four">
                <label>Spouse Surname<input type="text" name="family[spouse_surname]"></label>
                <label>Spouse First Name<input type="text" name="family[spouse_first_name]"></label>
                <label>Spouse Middle Name<input type="text" name="family[spouse_middle_name]"></label>
                <label>Spouse Name Extension<input type="text" name="family[spouse_name_extension]"></label>
            </div>

            <div class="field-grid three">
                <label>Occupation<input type="text" name="family[spouse_occupation]"></label>
                <label>Employer/Business Name<input type="text" name="family[spouse_employer]"></label>
                <label>Business Address<input type="text" name="family[spouse_business_address]"></label>
            </div>

            <div class="field-grid two">
                <label>Spouse Telephone No.<input type="text" name="family[spouse_tel]" data-no-uppercase></label>
                <div></div>
            </div>

            <div class="field-grid three">
                <label>Father's Surname<input type="text" name="family[father_surname]"></label>
                <label>Father's First Name<input type="text" name="family[father_first_name]"></label>
                <label>Father's Middle Name<input type="text" name="family[father_middle_name]"></label>
            </div>

            <div class="field-grid three">
                <label>Mother's Maiden Surname<input type="text" name="family[mother_surname]"></label>
                <label>Mother's First Name<input type="text" name="family[mother_first_name]"></label>
                <label>Mother's Middle Name<input type="text" name="family[mother_middle_name]"></label>
            </div>

            <div class="table-wrap">
                <table>
                    <caption>Children</caption>
                    <thead>
                        <tr>
                            <th>Full Name of Child</th>
                            <th>Date of Birth</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 8; $i++)
                            <tr>
                                <td><input type="text" name="children[{{ $i }}][name]"></td>
                                <td><input type="date" name="children[{{ $i }}][birth_date]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-education" data-section="pds-education">
            <h3 id="pds-education">III. Educational Background</h3>
            <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
                <button type="button" class="btn btn-secondary pds-add-college-btn">Add College Row</button>
                <button type="button" class="btn btn-secondary pds-add-grad-btn">Add Graduate Row</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Level</th>
                            <th>Name of School</th>
                            <th>Basic Education / Degree / Course</th>
                            <th>Period of Attendance (From)</th>
                            <th>Period of Attendance (To)</th>
                            <th>Highest Level / Units Earned</th>
                            <th>Year Graduated</th>
                            <th>Scholarship / Academic Honors Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['Elementary', 'Secondary', 'Vocational / Trade Course', 'College', 'Graduate Studies'] as $index => $level)
                            <tr data-level="{{ $level }}">
                                <td>
                                    {{ $level }}
                                    <input type="hidden" name="education[{{ $index }}][level]" value="{{ $level }}">
                                </td>
                                <td><input type="text" name="education[{{ $index }}][school]"></td>
                                <td><input type="text" name="education[{{ $index }}][course]"></td>
                                <td><input type="text" name="education[{{ $index }}][from]"></td>
                                <td><input type="text" name="education[{{ $index }}][to]"></td>
                                <td><input type="text" name="education[{{ $index }}][units]" data-no-uppercase></td>
                                <td><input type="text" name="education[{{ $index }}][year_graduated]" data-no-uppercase></td>
                                <td><input type="text" name="education[{{ $index }}][honors]"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-eligibility" data-section="pds-eligibility">
            <h3 id="pds-eligibility">IV. Civil Service Eligibility</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Career Service / RA 1080 (Board/Bar) / CES / CSEE / Barangay Eligibility / Driver's License</th>
                            <th>Rating</th>
                            <th>Date of Examination / Conferment</th>
                            <th>Place of Examination / Conferment</th>
                            <th>License Number</th>
                            <th>Date of Validity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 6; $i++)
                            <tr>
                                <td><input type="text" name="eligibility[{{ $i }}][type]"></td>
                                <td><input type="text" name="eligibility[{{ $i }}][rating]"></td>
                                <td><input type="text" name="eligibility[{{ $i }}][exam_date]"></td>
                                <td><input type="text" name="eligibility[{{ $i }}][place]"></td>
                                <td><input type="text" name="eligibility[{{ $i }}][license_no]" data-no-uppercase></td>
                                <td><input type="text" name="eligibility[{{ $i }}][validity]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-work-experience" data-section="pds-work-experience">
            <h3 id="pds-work-experience">V. Work Experience</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                            <tr>
                            <th>Inclusive Dates (From)</th>
                            <th>Inclusive Dates (To)</th>
                            <th>Position Title</th>
                            <th>Department / Agency / Office / Company</th>
                            <th>Status of Appointment</th>
                            <th>Gov't Service (Y/N)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 12; $i++)
                            <tr>
                                <td><input type="date" name="work[{{ $i }}][from]" class="work-from"></td>
                                <td>
                                    <input type="date" name="work[{{ $i }}][to]" class="work-to">
                                    <label class="toggle-switch" style="margin-left:8px;">
                                        <input type="checkbox" name="work[{{ $i }}][to_present]" value="1" class="work-to-present" aria-label="Present">
                                        <span class="toggle-slider" aria-hidden="true"></span>
                                        <span class="toggle-label">Present</span>
                                    </label>
                                </td>
                                <td><input type="text" name="work[{{ $i }}][position]"></td>
                                <td><input type="text" name="work[{{ $i }}][agency]"></td>
                                <td><input type="text" name="work[{{ $i }}][status]"></td>
                                <td>
                                    <select name="work[{{ $i }}][is_government]">
                                        <option value=""></option>
                                        <option value="Y">Y</option>
                                        <option value="N">N</option>
                                    </select>
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-voluntary-work" data-section="pds-voluntary-work">
            <h3 id="pds-voluntary-work">VI. Voluntary Work or Involvement in Civic / Non-Government / People / Voluntary Organization</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name and Address of Organization</th>
                            <th>Inclusive Dates (From)</th>
                            <th>Inclusive Dates (To)</th>
                            <th>Number of Hours</th>
                            <th>Position / Nature of Work</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 6; $i++)
                            <tr>
                                <td><input type="text" name="voluntary[{{ $i }}][organization]"></td>
                                <td><input type="text" name="voluntary[{{ $i }}][from]"></td>
                                <td><input type="text" name="voluntary[{{ $i }}][to]"></td>
                                <td><input type="text" name="voluntary[{{ $i }}][hours]" data-no-uppercase></td>
                                <td><input type="text" name="voluntary[{{ $i }}][position]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-learning-dev" data-section="pds-learning-dev">
            <h3 id="pds-learning-dev">VII. Learning and Development (L&D) Interventions / Training Programs Attended</h3>
            <div style="margin-bottom:8px;">
                <button type="button" class="btn btn-secondary pds-add-training-btn">Add Training Row</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Title of Learning and Development Interventions / Training Programs</th>
                            <th>Inclusive Dates (From)</th>
                            <th>Inclusive Dates (To)</th>
                            <th>Number of Hours</th>
                            <th>Type of LD (Managerial/Supervisory/Technical/etc.)</th>
                            <th>Conducted / Sponsored By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 10; $i++)
                            <tr>
                                <td><input type="text" name="training[{{ $i }}][title]"></td>
                                <td><input type="date" name="training[{{ $i }}][from]" class="training-from"></td>
                                <td><input type="date" name="training[{{ $i }}][to]" class="training-to"></td>
                                <td><input type="text" name="training[{{ $i }}][hours]" data-no-uppercase></td>
                                <td><input type="text" name="training[{{ $i }}][type]"></td>
                                <td><input type="text" name="training[{{ $i }}][sponsor]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-other-info" data-section="pds-other-info">
            <h3 id="pds-other-info">VIII. Other Information</h3>

            <div class="table-wrap">
                <table>
                    <caption>Special Skills and Hobbies</caption>
                    <tbody>
                        @for ($i = 0; $i < 7; $i++)
                            <tr>
                                <td><input type="text" name="other[skills][{{ $i }}]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <div class="table-wrap">
                <table>
                    <caption>Non-Academic Distinctions / Recognition</caption>
                    <tbody>
                        @for ($i = 0; $i < 7; $i++)
                            <tr>
                                <td><input type="text" name="other[distinctions][{{ $i }}]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>

            <div class="table-wrap">
                <table>
                    <caption>Membership in Association / Organization</caption>
                    <tbody>
                        @for ($i = 0; $i < 7; $i++)
                            <tr>
                                <td><input type="text" name="other[memberships][{{ $i }}]"></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-additional-questions" data-section="pds-additional-questions">
            <h3 id="pds-additional-questions">IX. Additional Questions</h3>

            <div class="question-list">
                <div class="question-item">
                    <p>
                        <strong>34.</strong> Are you related by consanguinity or affinity to the appointing or recommending authority, 
                        or to the chief of bureau/office/division where you will be appointed, or to the person who has immediate 
                        supervision over you in the Office, Bureau or Department where you will be appointed:
                    </p>

                    <div class="subquestion">
                        <p><strong>a.</strong> Within the third degree?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[34][a][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[34][a][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, give details 
                                <input type="text" name="questions[34][a][details]">
                            </label>
                        </div>
                    </div>

                    <div class="subquestion">
                        <p><strong>b.</strong> Within the fourth degree (for Local Government Unit - Career Employees)?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[34][b][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[34][b][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, give details 
                                <input type="text" name="questions[34][b][details]">
                            </label>
                        </div>
                    </div>
                </div>

                <div class="question-item">
                    <p><strong>35.</strong></p>

                    <div class="subquestion">
                        <p><strong>A.</strong> Have you ever been found guilty of any administrative offense?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[35][a][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[35][a][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, give details <input type="text" name="questions[35][a][details]"></label>
                        </div>

                        <p><strong>B.</strong> Have you been criminally charged before any court?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[35][b][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[35][b][answer]" value="No"> No
                            </label>
                            <div class="details-block">
                                <label class="details-field">If YES, give details
                                    <input type="text" name="questions[35][b][details]">
                                </label>
                                <div class="meta-row">
                                    <label class="meta-field">Date Filed
                                        <input type="date" name="questions[35][b][date_filed]"></label>
                                    <label class="meta-field">Status of Case/s
                                        <input type="text" name="questions[35][b][status]"></label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="question-item">
                    <p>
                        <strong>36.</strong>
                        Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?
                    </p>
                    <div class="answer-row">
                        <label>
                            <input type="radio" name="questions[36][answer]" value="Yes"> Yes
                        </label>
                        <label>
                            <input type="radio" name="questions[36][answer]" value="No"> No
                        </label>
                        <label class="details-field">If YES, give details <input type="text" name="questions[36][details]"></label>
                    </div>
                </div>

                <div class="question-item">
                    <p>
                        <strong>37.</strong>
                        Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?
                    </p>
                    <div class="answer-row">
                        <label>
                            <input type="radio" name="questions[37][answer]" value="Yes"> Yes
                        </label>
                        <label>
                            <input type="radio" name="questions[37][answer]" value="No"> No
                        </label>
                        <label class="details-field">If YES, give details <input type="text" name="questions[37][details]"></label>
                    </div>
                </div>

                <div class="question-item">
                    <p><strong>38.</strong></p>

                    <div class="subquestion">
                        <p><strong>A.</strong> Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[38][a][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[38][a][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, give details <input type="text" name="questions[38][a][details]"></label>
                        </div>

                        <p><strong>B.</strong> Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[38][b][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[38][b][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, give details <input type="text" name="questions[38][b][details]"></label>
                        </div>
                    </div>
                </div>

                <div class="question-item">
                    <p>
                        <strong>39.</strong>
                        Have you acquired the status of an immigrant or permanent resident of another country?
                    </p>
                    <div class="answer-row">
                        <label>
                            <input type="radio" name="questions[39][answer]" value="Yes"> Yes
                        </label>
                        <label>
                            <input type="radio" name="questions[39][answer]" value="No"> No
                        </label>
                        <label class="details-field">If YES, give details (country): <input type="text" name="questions[39][country]"></label>
                    </div>
                </div>

                <div class="question-item">
                    <p>
                        <strong>40.</strong>
                        Pursuant to: (a) Indigenous People's Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:
                    </p>

                    <div class="subquestion">
                        <p><strong>A.</strong> Are you a member of any indigenous group?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[40][a][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[40][a][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, please specify: <input type="text" name="questions[40][a][details]"></label>
                        </div>

                        <p><strong>B.</strong> Are you a person with disability?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[40][b][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[40][b][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, please specify ID No: <input type="text" name="questions[40][b][id]" data-no-uppercase></label>
                        </div>

                        <p><strong>C.</strong> Are you a solo parent?</p>
                        <div class="answer-row">
                            <label>
                                <input type="radio" name="questions[40][c][answer]" value="Yes"> Yes
                            </label>
                            <label>
                                <input type="radio" name="questions[40][c][answer]" value="No"> No
                            </label>
                            <label class="details-field">If YES, please specify ID No: <input type="text" name="questions[40][c][id]" data-no-uppercase></label>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-references" data-section="pds-references">
            <h3 id="pds-references">X. References</h3>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Address</th>
                            <th>Tel. No.</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < 3; $i++)
                            <tr>
                                <td><input type="text" name="references[{{ $i }}][name]"></td>
                                <td><input type="text" name="references[{{ $i }}][address]"></td>
                                <td><input type="text" name="references[{{ $i }}][tel]" data-no-uppercase></td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <section class="pds-section pds-pane" aria-labelledby="pds-declaration" data-section="pds-declaration">
            <h3 id="pds-declaration">XI. Declaration</h3>

            <p class="declaration-text">
                I declare under oath that the information provided in this Personal Data Sheet is true, correct, and complete.
                I understand that any false statement may be ground for administrative and/or criminal liability.
            </p>

            <div class="field-grid three">
                <label>Government Issued ID<input type="text" name="declaration[gov_id]" data-no-uppercase></label>
                <label>ID/License/Passport No.<input type="text" name="declaration[id_no]" data-no-uppercase></label>
                <label>Date/Place of Issuance<input type="text" name="declaration[id_issue]" data-no-uppercase></label>
            </div>

            <div class="field-grid two">
                <label>Signature Over Printed Name<input type="text" name="declaration[signature_name]" value="{{ strtoupper((string) ($user->name ?? '')) }}"></label>
                <label>Date Accomplished<input type="date" name="declaration[date_accomplished]"></label>
            </div>
        </section>

        <div class="pds-actions">
            <button type="button" class="btn btn-secondary pds-save-draft-btn">Save Draft</button>
            <button type="button" class="btn pds-print-btn">Print PDS</button>
            <div class="pds-save-feedback" style="display: none; margin-left: 12px; font-size: 0.9rem;"></div>
        </div>
    </form>

    <script>
        (function () {
            const form = document.querySelector('.pds-form');

            if (!form) {
                return;
            }

            const panes = Array.from(form.querySelectorAll('.pds-pane'));
            const navButtons = Array.from(form.querySelectorAll('.pds-nav-btn'));
            const sectionPicker = form.querySelector('#pds-section-picker');
            const prevBtn = form.querySelector('.pds-prev-btn');
            const nextBtn = form.querySelector('.pds-next-btn');
            const saveDraftBtn = form.querySelector('.pds-save-draft-btn');
            const saveFeedback = form.querySelector('.pds-save-feedback');

            if (panes.length === 0) {
                return;
            }

            form.classList.add('js-ready');

            // Helper: initialize flatpickr on date inputs within a container (used for dynamic rows)
            const initializeFlatpickrOn = function (container) {
                if (!window.flatpickr) return;
                const dateSelectors = 'input[type="date"], input[name*="date"], input[name$="[from]"], input[name$="[to]"]';
                const dateInputs = Array.from(container.querySelectorAll(dateSelectors)).filter(input => !input.closest('[data-section="pds-education"]'));
                dateInputs.forEach(input => {
                    // avoid double-init
                    if (input._flatpickr) return;
                    flatpickr(input, {
                        altInput: true,
                        altFormat: 'd/m/Y',
                        dateFormat: 'Y-m-d',
                        allowInput: true,
                    });
                    const fp = input._flatpickr;
                    if (fp && fp.altInput) {
                        fp.altInput.dataset.fpFor = input.name || '';
                    }
                });
            };

            // --- Training table reference (declared early so helpers can use it) ---
            const trainingTable = form.querySelector('section[data-section="pds-learning-dev"] table tbody');

            // Helper to get a date value (ISO Y-m-d) from an input, preferring flatpickr input
            const getInputDateValue = function (input) {
                if (!input) return '';
                if (input._flatpickr) {
                    return input._flatpickr.input.value || input.value || '';
                }
                return input.value || '';
            };

            // Sorting function: sorts rows in trainingTable by .training-from (most recent -> oldest).
            // - Missing From dates are treated as very old (so they go last).
            // - Tie-breaker: later To dates come first (missing To treated as Infinity so ongoing appear earlier).
            const sortTrainingRows = function () {
                if (!trainingTable) return;
                const rows = Array.from(trainingTable.querySelectorAll('tr'));
                const decorated = rows.map(function (tr, idx) {
                    const fromInput = tr.querySelector('.training-from');
                    const toInput = tr.querySelector('.training-to');
                    const fromVal = getInputDateValue(fromInput);
                    const toVal = getInputDateValue(toInput);

                    // For descending sort:
                    // - valid fromVal => timestamp
                    // - missing fromVal => treat as very old => -Infinity (so placed last)
                    const fromTime = fromVal ? (new Date(fromVal)).getTime() : -Infinity;

                    // For tie-breaker (toTime), treat missing toVal as Infinity so "ongoing" or unspecified-to entries rank earlier among same-from
                    const toTime = toVal ? (new Date(toVal)).getTime() : Infinity;

                    return { tr, fromTime, toTime, idx };
                });

                decorated.sort(function (a, b) {
                    if (a.fromTime === b.fromTime) {
                        // tie-breaker: later toTime first
                        if (a.toTime === b.toTime) return a.idx - b.idx;
                        return (b.toTime - a.toTime);
                    }
                    // primary: most recent fromTime first
                    return (b.fromTime - a.fromTime);
                });

                // re-append rows in sorted order
                decorated.forEach(function (d) {
                    trainingTable.appendChild(d.tr);
                });
            };

            // Initialize flatpickr on page load for existing inputs (excluding education)
            if (window.flatpickr) {
                initializeFlatpickrOn(document);

                // Wire up "Present" checkboxes for work rows to disable/clear the To field
                const presentCheckboxes = Array.from(form.querySelectorAll('.work-to-present'));

                const handlePresentChange = (e) => {
                    const target = e.target;
                    const tr = target.closest('tr');
                    if (!tr) return;
                    const toInput = tr.querySelector('.work-to');
                    if (!toInput) return;
                    const fp = toInput._flatpickr;

                    if (target.checked) {
                        // If this is being checked, uncheck any other present toggles
                        presentCheckboxes.forEach(cb => {
                            if (cb !== target && cb.checked) {
                                cb.checked = false;
                                const otherTr = cb.closest('tr');
                                const otherTo = otherTr && otherTr.querySelector('.work-to');
                                const otherFp = otherTo && otherTo._flatpickr;
                                if (otherFp) {
                                    otherFp.input.disabled = false;
                                    if (otherFp.altInput) otherFp.altInput.disabled = false;
                                } else if (otherTo) {
                                    otherTo.disabled = false;
                                }
                            }
                        });

                        // clear and disable this row's To field
                        if (fp) {
                            fp.clear();
                            if (fp.altInput) fp.altInput.disabled = true;
                            fp.input.disabled = true;
                        } else {
                            toInput.disabled = true;
                            toInput.value = '';
                        }
                    } else {
                        // re-enable the To field when unchecked
                        if (fp) {
                            fp.input.disabled = false;
                            if (fp.altInput) fp.altInput.disabled = false;
                        } else {
                            toInput.disabled = false;
                        }
                    }
                };

                // Normalize on load: if multiple are checked, keep the first and uncheck the rest
                const initiallyChecked = Array.from(form.querySelectorAll('.work-to-present')).filter(cb => cb.checked);
                if (initiallyChecked.length > 1) {
                    initiallyChecked.slice(1).forEach(cb => cb.checked = false);
                }
                // ensure the single remaining checked one triggers the handler to disable its To field
                if (initiallyChecked.length >= 1) {
                    handlePresentChange({ target: initiallyChecked[0] });
                }

                presentCheckboxes.forEach(cb => cb.addEventListener('change', handlePresentChange));
            }

            let activeIndex = 0;

            // Function to collect current section data
            const collectSectionData = function (paneElement) {
                const sectionKey = paneElement.dataset.section;
                if (!sectionKey) return null;

                const inputs = paneElement.querySelectorAll('input, select, textarea');
                const sectionData = {};

                inputs.forEach(function (input) {
                    const name = input.name;
                    if (!name) return;

                    if (input.type === 'checkbox') {
                        sectionData[name] = input.checked;
                    } else if (input.type === 'radio') {
                        if (input.checked) {
                            sectionData[name] = input.value;
                        }
                    } else {
                        sectionData[name] = input.value;
                    }
                });

                return {
                    key: sectionKey,
                    data: sectionData,
                };
            };
            
            // After collectSectionData, map any flatpickr altInputs back to their source names
            const collectSectionDataWithFlatpickr = function (paneElement) {
                const result = collectSectionData(paneElement);
                if (!result) return null;

                const sectionData = result.data;
                const altInputs = paneElement.querySelectorAll('[data-fp-for]');
                altInputs.forEach(function (alt) {
                    const targetName = alt.dataset.fpFor;
                    if (!targetName) return;
                    if (!sectionData.hasOwnProperty(targetName) || sectionData[targetName] === '') {
                        const original = paneElement.querySelector('[name="' + targetName + '"]');
                        if (original && original._flatpickr) {
                            sectionData[targetName] = original._flatpickr.input.value || original.value || '';
                        } else if (original) {
                            sectionData[targetName] = original.value || '';
                        } else {
                            sectionData[targetName] = alt.value || '';
                        }
                    }
                });

                return result;
            };

            // Function to populate form with existing data
            const populateFormData = function (pdsData) {
                if (!pdsData || !pdsData.section_data) return;

                const sectionData = pdsData.section_data;
                panes.forEach(function (pane) {
                    const sectionKey = pane.dataset.section;
                    const data = sectionData[sectionKey];

                    if (!data) return;

                    Object.entries(data).forEach(function ([fieldName, value]) {
                        const inputs = pane.querySelectorAll('[name="' + fieldName + '"]');
                        inputs.forEach(function (input) {
                            if (input.type === 'checkbox') {
                                input.checked = value === true || value === 'true' || value === '1';
                                // trigger change so present toggle handlers run
                                input.dispatchEvent(new Event('change', { bubbles: true }));
                            } else if (input.type === 'radio') {
                                input.checked = input.value === value;
                                if (input.checked) {
                                    input.dispatchEvent(new Event('change', { bubbles: true }));
                                }
                            } else {
                                // If this input is managed by flatpickr, use its API to set the date
                                if (input._flatpickr) {
                                    const fp = input._flatpickr;
                                    if (value === null || value === '') {
                                        try { fp.clear(); } catch (e) { input.value = '' }
                                    } else {
                                        try {
                                            fp.setDate(value, true, 'Y-m-d');
                                        } catch (e) {
                                            input.value = value || '';
                                        }
                                    }
                                } else {
                                    input.value = value || '';
                                }
                            }
                        });
                    });
                });
            };

            // Load existing PDS data (safe JSON helper)
            const pdsData = @json($pds->getAllSectionData());
            populateFormData({ section_data: pdsData });

            // Auto-uppercase text inputs and textareas (updates value and visual)
            const attachUpper = function (input) {
                if (!input) return;
                const applyUpper = function () {
                    const start = input.selectionStart;
                    const end = input.selectionEnd;
                    input.value = (input.value || '').toUpperCase();
                    try {
                        if (typeof start === 'number' && typeof end === 'number') {
                            input.setSelectionRange(start, end);
                        }
                    } catch (e) {
                        // ignore selection errors
                    }
                };

                input.addEventListener('input', applyUpper, { passive: true });
                applyUpper();
            };

            // Only uppercase text inputs that are NOT marked with data-no-uppercase
            const uppercaseInputs = Array.from(form.querySelectorAll('input[type="text"]:not([data-no-uppercase]), textarea:not([data-no-uppercase])'));
            uppercaseInputs.forEach(attachUpper);

            // Education dynamic rows (add/remove for College and Graduate)
            const educationTable = form.querySelector('section[data-section="pds-education"] table tbody');
            const addCollegeBtn = form.querySelector('.pds-add-college-btn');
            const addGradBtn = form.querySelector('.pds-add-grad-btn');

            const getMaxEducationIndex = function () {
                const inputs = form.querySelectorAll('input[name^="education["]');
                let max = -1;
                inputs.forEach(function (inp) {
                    const m = inp.name.match(/^education\[(\d+)\]/);
                    if (m) {
                        const idx = parseInt(m[1], 10);
                        if (idx > max) max = idx;
                    }
                });
                return max;
            };

            const makeRow = function (level, index, removable = true) {
                const tr = document.createElement('tr');
                tr.dataset.level = level;

                const displayLabel = removable ? (level + ' (Additional)') : level;

                const cols = [
                    `${displayLabel}<input type="hidden" name="education[${index}][level]" value="${level}">`,
                    `<input type="text" name="education[${index}][school]">`,
                    `<input type="text" name="education[${index}][course]">`,
                    `<input type="text" name="education[${index}][from]">`,
                    `<input type="text" name="education[${index}][to]">`,
                    `<input type="text" name="education[${index}][units]" data-no-uppercase>`,
                    `<input type="text" name="education[${index}][year_graduated]" data-no-uppercase>`,
                    `<div style="display:flex;gap:6px;align-items:center;"><input type="text" name="education[${index}][honors]">${removable ? '<button type="button" class="btn btn-secondary pds-remove-edu-btn">Remove</button>' : ''}</div>`
                ];

                cols.forEach(function (c) {
                    const td = document.createElement('td');
                    td.innerHTML = c;
                    tr.appendChild(td);
                });

                if (removable) {
                    tr.querySelectorAll('input[type="text"]').forEach(function (inp) {
                        if (!inp.hasAttribute('data-no-uppercase')) attachUpper(inp);
                    });
                }

                return tr;
            };

            const insertRowAfterLevel = function (levelName) {
                const max = getMaxEducationIndex();
                const nextIdx = max + 1;
                const targetRow = educationTable.querySelector(`tr[data-level="${levelName}"]`);
                const newRow = makeRow(levelName, nextIdx, true);

                if (targetRow && targetRow.nextSibling) {
                    // insert after the last contiguous rows with same level
                    let insertAfter = targetRow;
                    let node = targetRow.nextSibling;
                    while (node && node.dataset && node.dataset.level === levelName) {
                        insertAfter = node;
                        node = node.nextSibling;
                    }
                    insertAfter.parentNode.insertBefore(newRow, insertAfter.nextSibling);
                } else if (targetRow) {
                    targetRow.parentNode.appendChild(newRow);
                } else {
                    educationTable.appendChild(newRow);
                }
            };

            if (addCollegeBtn) {
                addCollegeBtn.addEventListener('click', function () {
                    insertRowAfterLevel('College');
                });
            }

            if (addGradBtn) {
                addGradBtn.addEventListener('click', function () {
                    insertRowAfterLevel('Graduate Studies');
                });
            }

            // Delegate remove button
            educationTable.addEventListener('click', function (e) {
                const btn = e.target.closest('.pds-remove-edu-btn');
                if (!btn) return;
                const tr = btn.closest('tr');
                if (tr) tr.remove();
            });

            // After dynamic helpers are ready: recreate any saved education rows that don't exist in DOM
            (function populateSavedEducationRows() {
                const eduSaved = pdsData['pds-education'] || {};
                const rows = {};

                Object.keys(eduSaved).forEach(function (k) {
                    const m = k.match(/^education\[(\d+)\]\[(.+)\]$/);
                    if (!m) return;
                    const idx = m[1];
                    const field = m[2];
                    rows[idx] = rows[idx] || {};
                    rows[idx][field] = eduSaved[k];
                });

                // find existing indexes in DOM
                const existing = new Set();
                form.querySelectorAll('input[name^="education["]').forEach(function (inp) {
                    const mm = inp.name.match(/^education\[(\d+)\]/);
                    if (mm) existing.add(mm[1]);
                });

                Object.keys(rows).forEach(function (idx) {
                    if (existing.has(idx)) return;

                    const level = rows[idx]['level'] || 'College';
                    const newRow = makeRow(level, idx, true);
                    educationTable.appendChild(newRow);

                    // set values for fields
                    Object.keys(rows[idx]).forEach(function (field) {
                        const selector = `[name="education[${idx}][${field}]"]`;
                        const input = educationTable.querySelector(selector);
                        if (input) {
                            input.value = rows[idx][field] ?? '';
                            if (!input.hasAttribute('data-no-uppercase')) attachUpper(input);
                        }
                    });
                });
            })();

            // Learning & Development dynamic rows (Add/Remove Training rows) with date pickers + sorting on save
            const addTrainingBtn = form.querySelector('.pds-add-training-btn');

            const getMaxTrainingIndex = function () {
                const inputs = form.querySelectorAll('input[name^="training["]');
                let max = -1;
                inputs.forEach(function (inp) {
                    const m = inp.name.match(/^training\[(\d+)\]/);
                    if (m) {
                        const idx = parseInt(m[1], 10);
                        if (idx > max) max = idx;
                    }
                });
                return max;
            };

            const makeTrainingRow = function (index, removable = true) {
                const tr = document.createElement('tr');

                const cols = [
                    `<input type="text" name="training[${index}][title]">`,
                    `<input type="date" name="training[${index}][from]" class="training-from">`,
                    `<input type="date" name="training[${index}][to]" class="training-to">`,
                    `<input type="text" name="training[${index}][hours]" data-no-uppercase>`,
                    `<input type="text" name="training[${index}][type]">`,
                    `<div style="display:flex;gap:6px;align-items:center;"><input type="text" name="training[${index}][sponsor]">${removable ? '<button type="button" class="btn btn-secondary pds-remove-training-btn">Remove</button>' : ''}</div>`
                ];

                cols.forEach(function (c) {
                    const td = document.createElement('td');
                    td.innerHTML = c;
                    tr.appendChild(td);
                });

                // attach uppercase behavior to newly created inputs if not excluded
                tr.querySelectorAll('input[type="text"]').forEach(function (inp) {
                    if (!inp.hasAttribute('data-no-uppercase')) attachUpper(inp);
                });

                return tr;
            };

            if (addTrainingBtn) {
                addTrainingBtn.addEventListener('click', function () {
                    const nextIdx = getMaxTrainingIndex() + 1;
                    const newRow = makeTrainingRow(nextIdx, true);
                    trainingTable.appendChild(newRow);

                    // initialize flatpickr on new row's date inputs
                    initializeFlatpickrOn(newRow);

                    // do NOT auto-sort while user is editing; sorting will occur after save
                });
            }

            // Delegate remove buttons for training
            trainingTable.addEventListener('click', function (e) {
                const btn = e.target.closest('.pds-remove-training-btn');
                if (!btn) return;
                const tr = btn.closest('tr');
                if (tr) {
                    tr.remove();
                    // sorting not required immediately; will run after save if needed
                }
            });

            // Recreate any saved training rows that don't exist in DOM
            (function populateSavedTrainingRows() {
                const trainingSaved = pdsData['pds-learning-dev'] || {};
                const rows = {};

                Object.keys(trainingSaved).forEach(function (k) {
                    const m = k.match(/^training\[(\d+)\]\[(.+)\]$/);
                    if (!m) return;
                    const idx = m[1];
                    const field = m[2];
                    rows[idx] = rows[idx] || {};
                    rows[idx][field] = trainingSaved[k];
                });

                // existing indexes in DOM
                const existing = new Set();
                form.querySelectorAll('input[name^="training["]') .forEach(function (inp) {
                    const mm = inp.name.match(/^training\[(\d+)\]/);
                    if (mm) existing.add(mm[1]);
                });

                Object.keys(rows).forEach(function (idx) {
                    if (existing.has(idx)) return;
                    const newRow = makeTrainingRow(idx, true);
                    trainingTable.appendChild(newRow);

                    // initialize flatpickr on newly appended row
                    initializeFlatpickrOn(newRow);

                    Object.keys(rows[idx]).forEach(function (field) {
                        const selector = `[name="training[${idx}][${field}]"]`;
                        const input = trainingTable.querySelector(selector);
                        if (input) {
                            // if flatpickr managed, set via setDate, else set value
                            if (input._flatpickr) {
                                try {
                                    input._flatpickr.setDate(rows[idx][field] || '', true, 'Y-m-d');
                                } catch (e) {
                                    input.value = rows[idx][field] ?? '';
                                }
                            } else {
                                input.value = rows[idx][field] ?? '';
                            }
                        }
                    });
                });

                // After restoring saved rows, sort them (most recent first)
                sortTrainingRows();
            })();

            // NOTE: Removed sorting on input change to avoid interrupting typing.
            // Sorting will now run only after a successful save for the L&D section or before export.

            const setActiveSection = function (targetId) {
                const nextIndex = panes.findIndex(function (pane) {
                    return pane.dataset.section === targetId;
                });

                if (nextIndex === -1) {
                    return;
                }

                activeIndex = nextIndex;

                panes.forEach(function (pane, index) {
                    pane.classList.toggle('active', index === activeIndex);
                });

                navButtons.forEach(function (button) {
                    button.classList.toggle('active', button.dataset.target === targetId);
                });

                if (sectionPicker instanceof HTMLSelectElement) {
                    sectionPicker.value = targetId;
                }

                if (prevBtn instanceof HTMLButtonElement) {
                    prevBtn.disabled = activeIndex === 0;
                }

                if (nextBtn instanceof HTMLButtonElement) {
                    nextBtn.disabled = activeIndex === panes.length - 1;
                }

                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            };

            const postSectionDraft = function (sectionInfo) {
                const csrfToken = form.querySelector('input[name="_token"]').value;
                const formData = new FormData();
                formData.append('_token', csrfToken);
                formData.append('section_key', sectionInfo.key);
                formData.append('section_data', JSON.stringify(sectionInfo.data));

                return fetch('{{ route("dashboard.employee.pds.save-draft") }}', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: formData,
                }).then(function (response) {
                    return response.json().then(function (data) {
                        return {
                            status: response.status,
                            ok: response.ok,
                            data: data,
                        };
                    });
                });
            };

            // Handle Save Draft button
            if (saveDraftBtn instanceof HTMLButtonElement) {
                saveDraftBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    saveDraftBtn.disabled = true;

                    const activePane = panes[activeIndex];
                    const sectionInfo = collectSectionDataWithFlatpickr(activePane);

                    if (!sectionInfo) {
                        saveDraftBtn.disabled = false;
                        return;
                    }

                    postSectionDraft(sectionInfo)
                        .then(function (result) {
                            if (result.ok && result.data.success) {
                                saveFeedback.style.display = 'inline';
                                saveFeedback.textContent = '✓ ' + result.data.message;
                                saveFeedback.style.color = '#10b981';

                                // Only sort training rows after a successful save of the L&D section
                                if (sectionInfo.key === 'pds-learning-dev') {
                                    // Re-initialize flatpickr on table just in case dynamic rows were added
                                    initializeFlatpickrOn(trainingTable);
                                    sortTrainingRows();
                                }

                                setTimeout(function () {
                                    saveFeedback.style.display = 'none';
                                }, 3000);
                            } else {
                                throw new Error(result.data.error || 'Failed to save section');
                            }
                        })
                        .catch(function (error) {
                            console.error('Error:', error);
                            saveFeedback.style.display = 'inline';
                            saveFeedback.textContent = '✗ ' + error.message;
                            saveFeedback.style.color = '#ef4444';

                            setTimeout(function () {
                                saveFeedback.style.display = 'none';
                            }, 5000);
                        })
                        .finally(function () {
                            saveDraftBtn.disabled = false;
                        });
                });
            }

            // Handle Print PDS button (Export to Excel)
            const printBtn = form.querySelector('.pds-print-btn');
            if (printBtn instanceof HTMLButtonElement) {
                printBtn.addEventListener('click', function (e) {
                    e.preventDefault();
                    printBtn.disabled = true;
                    printBtn.textContent = 'Exporting...';

                    const sectionsToSave = panes
                        .map(function (pane) {
                            return collectSectionDataWithFlatpickr(pane);
                        })
                        .filter(function (item) {
                            return item !== null;
                        });

                    Promise.all(
                        sectionsToSave.map(function (sectionInfo) {
                            return postSectionDraft(sectionInfo);
                        })
                    )
                        .then(function () {
                            // After successfully saving all sections, ensure training rows are sorted before exporting
                            initializeFlatpickrOn(trainingTable);
                            sortTrainingRows();

                            return fetch('{{ route("dashboard.employee.pds.export") }}', {
                                method: 'GET',
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/octet-stream, application/json' },
                            });
                        })
                        .then(function (response) {
                            if (!response.ok) {
                                return response.json().then(function (body) {
                                    throw new Error(body.message || 'Export failed');
                                }).catch(function () {
                                    throw new Error('Export failed (HTTP ' + response.status + ')');
                                });
                            }
                            return response.blob();
                        })
                        .then(function (blob) {
                            const url = URL.createObjectURL(blob);
                            const link = document.createElement('a');
                            link.href = url;
                            link.download = 'PDS_' + new Date().toISOString().split('T')[0] + '.xlsx';
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(url);
                        })
                        .catch(function (error) {
                            console.error('PDS export error:', error);
                            alert('Failed to export PDS: ' + error.message);
                        })
                        .finally(function () {
                            printBtn.disabled = false;
                            printBtn.textContent = 'Print PDS';
                        });
                });
            }

            navButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    setActiveSection(button.dataset.target || '');
                });
            });

            if (sectionPicker instanceof HTMLSelectElement) {
                sectionPicker.addEventListener('change', function () {
                    setActiveSection(sectionPicker.value);
                });
            }

            if (prevBtn instanceof HTMLButtonElement) {
                prevBtn.addEventListener('click', function () {
                    if (activeIndex <= 0) {
                        return;
                    }

                    setActiveSection(panes[activeIndex - 1].dataset.section || '');
                });
            }

            if (nextBtn instanceof HTMLButtonElement) {
                nextBtn.addEventListener('click', function () {
                    if (activeIndex >= panes.length - 1) {
                        return;
                    }

                    setActiveSection(panes[activeIndex + 1].dataset.section || '');
                });
            }

            // Final: ensure training rows sorted after any initial populate (already sorted in populateSavedTrainingRows too)
            sortTrainingRows();

            setActiveSection(panes[0].dataset.section || '');
        })();
    </script>
@endsection