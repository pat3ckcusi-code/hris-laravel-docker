<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Document Request Printout</title>
    <style>
        @page {
            margin: 0;
            size: A4;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #000;
            background: #fff;
        }

        .sheet {
            width: 794px;
            min-height: 1123px;
            margin: 0 auto;
            position: relative;
            padding: 200px 52px 140px;
            box-sizing: border-box;
        }

        .doc-header {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 190px;
            object-fit: cover;
        }

        .doc-footer {
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 110px;
            object-fit: cover;
        }

        .title {
            text-align: center;
            letter-spacing: 0.38em;
            font-weight: 700;
            margin: 0 0 26px;
        }

        .section {
            font-size: 16px;
            margin-bottom: 18px;
            text-align: justify;
        }

        .to-whom {
            font-weight: 700;
            margin-bottom: 14px;
        }

        .signature {
            margin-top: 56px;
            text-align: center;
            font-size: 15px;
            font-weight: 700;
        }

        .signature small {
            display: block;
            font-weight: 400;
        }

        .meta {
            margin-top: 44px;
            font-size: 12px;
            line-height: 1.45;
        }

        @media print {
            .sheet {
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <img class="doc-header" src="{{ asset('assets/frontdesk/Header.png') }}" alt="Front Desk Header">
        <img class="doc-footer" src="{{ asset('assets/frontdesk/Footer.png') }}" alt="Front Desk Footer">

        <h2 class="title">CERTIFICATION</h2>

        <div class="section to-whom">TO WHOM IT MAY CONCERN:</div>

        <div class="section">
            This is to certify that the records pertaining to <strong>{{ strtoupper($requestItem['employee_name']) }}</strong>
            in this Office refer to one and the same person, currently employed with the City Government of
            Calapan as <strong>{{ strtoupper($designation) }}</strong>.
        </div>

        <div class="section">
            This certification is issued upon the request of <strong>{{ strtoupper($requestItem['employee_name']) }}</strong>
            for whatever legal or employment purpose it may serve and is based on the records of this office as of the date of issuance.
        </div>

        <div class="section">
            Issued this <strong>{{ now()->format('jS \d\a\y \o\f F, Y') }}</strong> in the City of Calapan, Philippines.
        </div>

        <div class="signature">
            MARIAN TERESA G. TAGUPA, LPT, MBA, JD
            <small>City Human Resource Management Officer</small>
            <small>City Government of Calapan</small>
        </div>

        <div class="meta">
            Not Valid without seal.<br>
            OR #:<br>
            Date:<br>
            Prepared by:<br>
            Verified by:
        </div>
    </div>

    <script>
        window.addEventListener('load', function () {
            window.print();
        });
    </script>
</body>
</html>
