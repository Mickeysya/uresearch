/*
 * Round 5/6 PART G.4/8. Tiny Node runner for the aggregation-parity
 * check -- reads one JSON file (argv[1]: { rows, scenarios }), applies
 * each scenario to public/js/chloe-insights-aggregate.js, and prints the
 * results as JSON on stdout. tests/Feature/InsightsAggregationParityTest.php
 * invokes this as a subprocess and compares the result against the same
 * scenarios run through App\Modules\Chloe\Support\InsightsAggregator
 * (the PHP reference) for the SAME rows -- never called from the page
 * itself, test-only.
 */
'use strict';

var fs = require('fs');
var path = require('path');
var aggregate = require(path.join(__dirname, '..', '..', 'public', 'js', 'chloe-insights-aggregate.js'));

var inputPath = process.argv[2];
var input = JSON.parse(fs.readFileSync(inputPath, 'utf8'));
var rows = input.rows;
var scenarios = input.scenarios;

var results = scenarios.map(function (scenario) {
    switch (scenario.op) {
        case 'context':
            return aggregate.context(rows, scenario.dim);
        case 'value':
            return aggregate.value(rows, scenario.dim, scenario.filters);
        case 'highlightCounts':
            return aggregate.highlightCounts(
                aggregate.filteredRows(rows, scenario.filters),
                scenario.dim,
                scenario.highlight
            );
        case 'summaryCounts':
            return aggregate.summaryCounts(
                aggregate.filteredRows(rows, scenario.filters),
                scenario.filters,
                scenario.highlight
            );
        case 'forecastMonths':
            return aggregate.forecastMonths(rows, scenario.filters, scenario.roomScopeFilters, scenario.nowMonth, scenario.maxMonths);
        case 'groupPeriods':
            return aggregate.groupPeriods(
                aggregate.forecastMonths(rows, scenario.filters, scenario.roomScopeFilters, scenario.nowMonth, scenario.maxMonths).months,
                scenario.grain
            );
        case 'filteredRowsCount':
            return aggregate.filteredRows(rows, scenario.filters).length;
        default:
            throw new Error('Unknown scenario op: ' + scenario.op);
    }
});

process.stdout.write(JSON.stringify(results));
