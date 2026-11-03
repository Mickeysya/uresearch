{{-- GA/GRA certification letter. Everything it says is built by
     Support\LetterRenderer::certificationData(); the layout is shared with
     the appointment letter. --}}
@extends('nureen::letters.layout')

@section('title', 'GA/GRA Certification Letter'.($reference ? ' '.$reference : ''))
