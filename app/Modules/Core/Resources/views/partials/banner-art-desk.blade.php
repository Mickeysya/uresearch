{{--
    Banner artwork: an administrator's desk — monitor, wall clock, phone,
    pen cup, books and a plant. Used by the CGS dashboard.

    Rendered by <x-core::welcome-banner art="desk" />.

    Same 240x140 viewBox and the same palette as banner-art-graduate, so the
    two banners read as one family and the CSS that sizes them (by height,
    via --banner-art-height) needs no per-artwork special case.
--}}
<svg class="dash-welcome-art" viewBox="0 0 240 140" role="presentation" aria-hidden="true" focusable="false">
    {{-- soft glow under the desk --}}
    <ellipse cx="122" cy="124" rx="112" ry="20" fill="#FFFFFF" opacity="0.05"/>

    {{-- sparkles --}}
    <g fill="#FFFFFF">
        <path d="M16 30 L18 34.8 L23 37 L18 39.2 L16 44 L14 39.2 L9 37 L14 34.8 Z" opacity="0.5"/>
        <path d="M86 20 L87.6 23.8 L91 25 L87.6 26.2 L86 30 L84.4 26.2 L81 25 L84.4 23.8 Z" opacity="0.35"/>
        <path d="M170 22 L171.8 26.2 L176 28 L171.8 29.8 L170 34 L168.2 29.8 L164 28 L168.2 26.2 Z" opacity="0.4"/>
        <circle cx="52" cy="56" r="1.9" opacity="0.32"/>
        <circle cx="236" cy="96" r="1.7" opacity="0.28"/>
        <circle cx="118" cy="16" r="1.6" opacity="0.3"/>
    </g>

    {{-- wall clock, top right --}}
    <g>
        <circle cx="210" cy="38" r="17" fill="#F4F7FB"/>
        <circle cx="210" cy="38" r="13.5" fill="#FFFFFF"/>
        <circle cx="210" cy="38" r="13.5" fill="none" stroke="#D5DDEA" stroke-width="1.2"/>
        {{-- hands: a little past ten --}}
        <path d="M210 38 L210 29" stroke="#2B3A5C" stroke-width="2.2" stroke-linecap="round"/>
        <path d="M210 38 L216 41" stroke="#2B3A5C" stroke-width="2" stroke-linecap="round"/>
        <circle cx="210" cy="38" r="1.8" fill="#E8B04B"/>
    </g>

    {{-- potted plant, left --}}
    <g>
        <path d="M36 106 Q28 90 32 74 Q42 88 39 106 Z" fill="#4FA86B"/>
        <path d="M38 106 Q48 92 60 86 Q54 102 42 110 Z" fill="#6FC489"/>
        <path d="M35 106 Q26 96 14 94 Q22 106 33 110 Z" fill="#3E8F58"/>
        <path d="M37 102 Q39 88 46 78 Q46 92 39 101 Z" fill="#5FB87C"/>
        <path d="M24 112 L48 112 L45 130 L27 130 Z" fill="#E8EDF5"/>
        <path d="M24 112 L48 112 L47.4 117 L24.6 117 Z" fill="#D5DDEA"/>
        <rect x="21" y="105" width="30" height="9" rx="3" fill="#F4F7FB"/>
    </g>

    {{-- monitor --}}
    <g>
        {{-- stand --}}
        <rect x="98" y="104" width="16" height="15" fill="#C2CDDE"/>
        <rect x="82" y="118" width="48" height="8" rx="4" fill="#B3C0D4"/>
        {{-- bezel + screen --}}
        <rect x="58" y="42" width="96" height="63" rx="5" fill="#FFFFFF"/>
        <rect x="62" y="46" width="88" height="55" rx="3" fill="#E9F0FA"/>
        {{-- what is on the screen: a list on the left, a card on the right --}}
        <rect x="68" y="52" width="26" height="4.5" rx="2.25" fill="#5B8FD4"/>
        <circle cx="140" cy="54" r="1.6" fill="#C3D0E4"/>
        <circle cx="145" cy="54" r="1.6" fill="#C3D0E4"/>
        <rect x="68" y="64" width="46" height="3.5" rx="1.75" fill="#C3D0E4"/>
        <rect x="68" y="72" width="36" height="3.5" rx="1.75" fill="#C3D0E4"/>
        <rect x="68" y="80" width="42" height="3.5" rx="1.75" fill="#C3D0E4"/>
        <rect x="68" y="88" width="30" height="3.5" rx="1.75" fill="#D6DFEC"/>
        <rect x="119" y="62" width="27" height="31" rx="3" fill="#FFFFFF"/>
        <rect x="123" y="68" width="19" height="3" rx="1.5" fill="#D9E2EF"/>
        <rect x="123" y="75" width="13" height="3" rx="1.5" fill="#D9E2EF"/>
        <rect x="123" y="84" width="19" height="5" rx="2.5" fill="#8FC9A8"/>
    </g>

    {{-- phone, propped beside the monitor --}}
    <g>
        <rect x="158" y="92" width="17" height="34" rx="4" fill="#2B3A5C"/>
        <rect x="160.5" y="95.5" width="12" height="26" rx="2" fill="#5B8FD4"/>
        <rect x="164" y="123" width="5" height="1.4" rx="0.7" fill="#6B7A96"/>
    </g>

    {{-- pen cup --}}
    <g>
        <path d="M179 108 L193 108 L191.5 126 L180.5 126 Z" fill="#E8EDF5"/>
        <path d="M179 108 L193 108 L192.6 112 L179.4 112 Z" fill="#D5DDEA"/>
        <rect x="182" y="94" width="2.6" height="15" rx="1.3" fill="#E8945A"/>
        <rect x="186" y="90" width="2.6" height="19" rx="1.3" fill="#5B8FD4"/>
        <rect x="189.6" y="96" width="2.6" height="13" rx="1.3" fill="#E8B04B"/>
    </g>

    {{-- book stack, far right --}}
    <g>
        <rect x="197" y="114" width="36" height="12" rx="2.5" fill="#E8945A"/>
        <rect x="197" y="114" width="36" height="3.5" rx="1.75" fill="#F2AC7B"/>
        <rect x="200" y="104" width="30" height="10" rx="2" fill="#F2F0E8"/>
        <rect x="200" y="104" width="30" height="3" rx="1.5" fill="#FFFFFF"/>
        <rect x="203" y="95" width="24" height="9" rx="2" fill="#6FC489"/>
        <rect x="203" y="95" width="24" height="2.8" rx="1.4" fill="#8FD6A5"/>
    </g>
</svg>
