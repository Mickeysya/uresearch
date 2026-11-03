{{-- Supervision appointment letter. Everything it says is built by
     Support\LetterRenderer::appointmentData(); the layout is shared with the
     certification letter. --}}
@extends('nureen::letters.layout')

@section('title', 'Supervisor Appointment Letter'.($reference ? ' '.$reference : ''))
