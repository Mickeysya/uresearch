@extends('core::layouts.app')

@section('title', 'Examiner Nomination')

@section('content')
<div class="card-container-inline">
    <x-core::page-header title="Nominating an examiner panel" />

    <div class="card card-wide">
        <div class="empty-state">
            <p><b>Panels are no longer nominated here.</b></p>
            <p class="queue-meta">
                The supervisor chooses the panel and the Academic Executive compiles it, and the
                list is agreed before it reaches the Centre for Graduate Studies. Appointment
                Letters now starts from that finished list: CGS imports it, prepares each
                candidate's pack, and the Dean approves.
            </p>
            <p class="queue-meta">
                Nothing you filed before is lost. Anything already approved is on
                <b>Issued Appointments</b> at CGS, and anything still moving is on its
                reviewer's queue.
            </p>
        </div>
    </div>
</div>
@endsection
