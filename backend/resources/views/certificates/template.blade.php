<!DOCTYPE html>
<html dir="{{ $isEn ? 'ltr' : 'rtl' }}" lang="{{ $isEn ? 'en' : 'ar' }}">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<style>
@font-face {
    font-family: 'Cairo';
    src: url('{{ $fontRegularPath }}') format('truetype');
    font-weight: normal;
    font-style: normal;
}
@font-face {
    font-family: 'Cairo';
    src: url('{{ $fontBoldPath }}') format('truetype');
    font-weight: bold;
    font-style: normal;
}

@page {
    size: 841.89pt 595.28pt;
    margin: 0;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    width: 1122px;
    height: 794px;
    font-family: 'Cairo', sans-serif;
    background: #ffffff;
    color: #2d3748;
    direction: {{ $isEn ? 'ltr' : 'rtl' }};
}

/* ============================================================
   PAGE WRAPPER
   ============================================================ */
.page {
    position: relative;
    width: 1122px;
    height: 794px;
    background: #ffffff;
    overflow: hidden;
}

/* ============================================================
   DOUBLE BORDER FRAME
   ============================================================ */
.border-navy {
    position: absolute;
    top: 16px; left: 16px; right: 16px; bottom: 16px;
    border: 4px solid #1a365d;
}

.border-gold {
    position: absolute;
    top: 25px; left: 25px; right: 25px; bottom: 25px;
    border: 1.5px solid #c9a84c;
}

/* Gold L-shape corner accents */
.corner {
    position: absolute;
    width: 26px;
    height: 26px;
    border-color: #c9a84c;
    border-style: solid;
}
.corner-tl { top: 31px;  left: 31px;  border-width: 3px 0 0 3px; }
.corner-tr { top: 31px;  right: 31px; border-width: 3px 3px 0 0; }
.corner-bl { bottom: 31px; left: 31px;  border-width: 0 0 3px 3px; }
.corner-br { bottom: 31px; right: 31px; border-width: 0 3px 3px 0; }

/* ============================================================
   MAIN CONTENT AREA
   ============================================================ */
.cert-body {
    position: absolute;
    top: 36px; left: 36px; right: 36px; bottom: 36px;
    padding: 16px 80px 14px;
    text-align: center;
}

/* ============================================================
   HEADER: LOGO + INSTITUTION NAME
   ============================================================ */
.logo-img {
    max-height: 52px;
    max-width: 150px;
    display: block;
    margin: 0 auto 5px;
}

.institution-name {
    font-size: 15px;
    font-weight: bold;
    color: #1a365d;
    letter-spacing: 3px;
    text-transform: uppercase;
}

/* ============================================================
   DIVIDERS
   ============================================================ */
.divider {
    width: 100%;
    height: 1px;
    background: #c9a84c;
    margin: 9px 0;
}

.divider-narrow {
    width: 220px;
    height: 1px;
    background: #c9a84c;
    margin: 5px auto;
}

/* Gold diamond ornament between dividers */
.ornament {
    font-size: 12px;
    color: #c9a84c;
    line-height: 1;
    margin: 0;
}

/* ============================================================
   CERTIFICATE MAIN TITLE
   ============================================================ */
.cert-title {
    font-size: 30px;
    font-weight: bold;
    color: #1a365d;
    letter-spacing: 4px;
    text-transform: uppercase;
    margin: 7px 0 3px;
}

/* ============================================================
   CERTIFY STATEMENT
   ============================================================ */
.certify-text {
    font-size: 13px;
    color: #718096;
    margin: 5px 0 3px;
}

/* ============================================================
   STUDENT NAME
   ============================================================ */
.student-name {
    font-size: 46px;
    font-weight: bold;
    color: #1a365d;
    line-height: 1.1;
    padding-bottom: 5px;
    border-bottom: 2px solid #c9a84c;
    margin: 2px 60px 4px;
}

/* ============================================================
   COURSE BLOCK
   ============================================================ */
.course-statement {
    font-size: 13px;
    color: #718096;
    margin: 5px 0 2px;
}

.course-name {
    font-size: 20px;
    font-weight: bold;
    color: #1a365d;
    margin: 0 0 4px;
}

/* ============================================================
   BOTTOM SECTION: DATE | SEAL | SIGNATURE
   ============================================================ */
.bottom-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 6px;
}

.bottom-table td {
    width: 33.33%;
    text-align: center;
    vertical-align: middle;
    padding: 0 8px;
}

.field-value {
    font-size: 12px;
    color: #2d3748;
    font-weight: bold;
    margin-bottom: 3px;
}

.field-line {
    width: 130px;
    height: 1px;
    background: #2d3748;
    margin: 0 auto 3px;
}

.field-label {
    font-size: 10px;
    color: #718096;
    letter-spacing: 1.5px;
    text-transform: uppercase;
}

/* ============================================================
   OFFICIAL SEAL (CSS circle)
   ============================================================ */
.seal {
    width: 74px;
    height: 74px;
    border: 3px solid #c9a84c;
    border-radius: 37px;
    margin: 0 auto;
    padding: 4px;
}

.seal-inner {
    width: 60px;
    height: 60px;
    border: 1px solid #c9a84c;
    border-radius: 30px;
    padding-top: 14px;
}

.seal-text {
    font-size: 8px;
    font-weight: bold;
    color: #c9a84c;
    text-transform: uppercase;
    letter-spacing: 1px;
    line-height: 1.3;
}
</style>
</head>
<body>
<div class="page">

    {{-- Double border frame --}}
    <div class="border-navy"></div>
    <div class="border-gold"></div>

    {{-- Gold corner L-shapes --}}
    <div class="corner corner-tl"></div>
    <div class="corner corner-tr"></div>
    <div class="corner corner-bl"></div>
    <div class="corner corner-br"></div>

    {{-- Main content --}}
    <div class="cert-body">

        {{-- Header --}}
        @if($logoBase64)
            <img class="logo-img" src="data:image/png;base64,{{ $logoBase64 }}" alt=""/>
        @endif
        @if($appName)
            <div class="institution-name">{{ $appName }}</div>
        @endif

        {{-- Top ornamental divider --}}
        <div class="divider"></div>

        {{-- Certificate title --}}
        <div class="cert-title">
            {{ $isEn ? 'Certificate of Completion' : 'شهادة إتمام' }}
        </div>

        {{-- Narrow gold line --}}
        <div class="divider-narrow"></div>

        {{-- Certify statement --}}
        <div class="certify-text">{{ $certifiedLabel }}</div>

        {{-- Student name with gold underline --}}
        <div class="student-name">{{ $studentName }}</div>

        {{-- Course statement --}}
        <div class="course-statement">{{ $courseLabel }}</div>

        {{-- Course name --}}
        <div class="course-name">{{ $courseName }}</div>

        {{-- Bottom ornamental divider --}}
        <div class="divider"></div>

        {{-- Bottom row: Date | Official Seal | Signature --}}
        <table class="bottom-table">
            <tr>
                <td>
                    <div class="field-value">{{ $date }}</div>
                    <div class="field-line"></div>
                    <div class="field-label">{{ $isEn ? 'Date of Completion' : 'تاريخ الإتمام' }}</div>
                </td>
                <td>
                    <div class="seal">
                        <div class="seal-inner">
                            <div class="seal-text">
                                {{ $isEn ? 'OFFICIAL' : 'الختم' }}<br/>
                                {{ $isEn ? 'SEAL' : 'الرسمي' }}
                            </div>
                        </div>
                    </div>
                </td>
                <td>
                    <div class="field-line"></div>
                    <div class="field-label">{{ $isEn ? 'Authorized Signature' : 'التوقيع المعتمد' }}</div>
                </td>
            </tr>
        </table>

    </div>
</div>
</body>
</html>
