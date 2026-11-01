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
