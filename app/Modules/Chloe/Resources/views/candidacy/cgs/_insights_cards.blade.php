            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">When do candidacies expire?</h2>
                <p class="wsi-layer-desc">Students by expiry date, split by what they have done so far. Projection from known expiry dates, not a prediction.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-forecast">
                <div class="wsi-card-head-row">
                    <div>
                        <h3 class="wsi-card-title">Candidacy expiry forecast</h3>
                        <p class="wsi-card-subtitle" id="wci-forecast-subtitle">Month by month.</p>
                    </div>
                    <div class="wsi-segctl" id="wci-grain" role="group" aria-label="Time grain">
                        <button type="button" data-grain="year" aria-pressed="false">Year</button>
                        <button type="button" data-grain="quarter" aria-pressed="false">Quarter</button>
                        <button type="button" data-grain="month" aria-pressed="false">Month</button>
                    </div>
                </div>
                <nav class="wsi-breadcrumb" id="wci-breadcrumb" hidden></nav>
                <div class="wsi-card-body" id="wci-forecast"></div>
                <p class="wsi-footer-note">Bars = students expiring in that period, by status category (the number above a bar is its total). Line = running total of the periods shown. Based on the expiry dates on record today; an approved appeal moves a date and the chart follows. Students past their expiry date are on the Dismissal List.</p>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Who expires in the next three months?</h2>
                <p class="wsi-layer-desc">The students CGS can still reach before the expiry date, by how close it is.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-bands">
                <h3 class="wsi-card-title">Expiring within 3 months</h3>
                <p class="wsi-card-subtitle">Students by how many months are left, split by status category. Months are counted like the reminder milestones.</p>
                <div class="wsi-card-body" id="wci-bands"></div>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-students">
                <div class="wsi-card-head-row">
                    <div>
                        <h3 class="wsi-card-title">Students expiring within 3 months</h3>
                        <p class="wsi-card-subtitle" id="wci-students-subtitle">Closest expiry date first; students with no action yet first on a tie. Follows the filters and whatever you highlight above.</p>
                    </div>
                    <div class="wsi-field"><label for="wci-search">Search</label><input class="wsi-select" type="search" id="wci-search" placeholder="Student name or ID" autocomplete="off"></div>
                </div>
                <div class="wsi-card-body" id="wci-students"></div>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Are the reminders working?</h2>
                <p class="wsi-layer-desc">Automatic expiry reminders (3, 2 and 1 month before), and whether students then act.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-reminders">
                <div class="wsi-card-head-row">
                    <div>
                        <h3 class="wsi-card-title">Reminders sent</h3>
                        <p class="wsi-card-subtitle">The last 6 months and this month, by milestone.</p>
                    </div>
                    <div class="wsi-tile" id="wci-response-tile" aria-live="polite"></div>
                </div>
                <div class="wsi-card-body" id="wci-reminders"></div>
                <p class="wsi-footer-note">Line = students who submitted the Softbound thesis or an appeal within 30 days after a reminder, counted in the month the reminder was sent. Response rate = those reminders divided by all reminders in the six months shown plus this month.</p>
            </div>

            <div class="wsi-layer-head">
                <h2 class="wsi-layer-title">Where are the dismissals?</h2>
                <p class="wsi-layer-desc">The live Dismissal List and the students the Registry has confirmed so far.</p>
            </div>

            <div class="wsi-span-12 wsi-card" id="wsi-card-dismissals">
                <div class="wsi-card-head-row">
                    <div>
                        <h3 class="wsi-card-title">Dismissal pipeline</h3>
                        <p class="wsi-card-subtitle">Students dismissed per month, the last 12 months.</p>
                    </div>
                    <div class="wsi-tile-row" id="wci-dismissal-tiles"></div>
                </div>
                <div class="wsi-card-body" id="wci-dismissals"></div>
                <p class="wsi-footer-note">The two numbers are the Dismissal List's own counts for the same filters. A student is counted in the month the Registry's result was uploaded and applied.</p>
            </div>
