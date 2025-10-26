(function ($) {
  "use strict";

  // ---------- small utils ----------
  function joinUrl(base, path) {
    if (!base) return path || "";
    return String(base).replace(/\/+$/, "") + "/" + String(path || "").replace(/^\/+/, "");
  }
  function withNonce(url, nonce) {
    return url + (url.indexOf("?") >= 0 ? "&" : "?") + "_wpnonce=" + encodeURIComponent(nonce || "");
  }
  function getRestRoot() {
    // Prefer localized root
    if (window.VLLAS && VLLAS.rest && VLLAS.rest.root) return VLLAS.rest.root;

    // Fallback: read from the button (rendered by PHP on the settings page)
    var btn = document.getElementById("vl-las-run-audit");
    if (btn && btn.dataset && btn.dataset.restRoot) return btn.dataset.restRoot;

    // Last resort: compute
    return (location.origin || "") + "/wp-json/vl-las/v1";
  }
  function getNonce() {
    // Prefer localized nonce
    if (window.VLLAS && VLLAS.rest && VLLAS.rest.nonce) return VLLAS.rest.nonce;

    // Fallback: read from the button
    var btn = document.getElementById("vl-las-run-audit");
    if (btn && btn.dataset && btn.dataset.nonce) return btn.dataset.nonce;

    return "";
  }

  // Safer date printer: handles seconds or ms timestamps and "YYYY-MM-DD HH:mm:ss"
  function asDate(ts) {
    if (ts == null) return "";
    if (typeof ts === "number") {
      if (ts < 1e12) ts = ts * 1000; // seconds -> ms
      var dNum = new Date(ts);
      return isNaN(dNum.getTime()) ? String(ts) : dNum.toLocaleString();
    }
    var isoTry = Date.parse(String(ts).replace(/-/g, "/").replace(" ", "T"));
    if (!isNaN(isoTry)) return new Date(isoTry).toLocaleString();
    var d = new Date(ts);
    return isNaN(d.getTime()) ? String(ts) : d.toLocaleString();
  }

  function captureHTML() {
    try {
      var doc = document.documentElement;
      if (!doc) return null;
      return "<!DOCTYPE html>" + doc.outerHTML;
    } catch (e) {
      return null;
    }
  }

  function downloadBlob(text, filename) {
    var blob = new Blob([text], { type: "application/json" });
    var url = URL.createObjectURL(blob);
    var a = document.createElement("a");
    a.href = url;
    a.download = filename || "report.json";
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(url);
      document.body.removeChild(a);
    }, 100);
  }

  function escapeHtml(s) {
    return String(s == null ? "" : s)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  // Guard: only run on our settings screen (the placeholders exist)
  function onSettingsPage() {
    return (
      !!document.getElementById("vl-las-audit-result") ||
      !!document.getElementById("vl-las-audit-list") ||
      !!document.getElementById("vl-las-soc2-run")
    );
  }

  // ---------- Pretty rendering & PDF ----------
  function renderPrettyReport(r) {
    var $wrap = $('<div class="vl-las-report-pretty"/>');

    var score = (r.summary && typeof r.summary.score !== "undefined") ? r.summary.score : null;
    var headHtml =
      '<div class="vl-las-report-head" style="padding:8px 10px;border:1px solid #ccd0d4;border-radius:6px;background:#fff;">' +
      '<div><strong>URL:</strong> ' + escapeHtml(r.url || "(n/a)") + "</div>" +
      '<div><strong>When:</strong> ' + escapeHtml(r.created_at ? asDate(r.created_at) : "(n/a)") + "</div>" +
      '<div><strong>Passed:</strong> ' + escapeHtml(r.summary?.passed ?? "-") + " / " + escapeHtml(r.summary?.total ?? "-") + "</div>" +
      '<div><strong>Score:</strong> ' + (score !== null ? escapeHtml(score + "%") : "—") + "</div>" +
      "</div>";
    $wrap.append($(headHtml));

    var items = Array.isArray(r.findings) ? r.findings : (Array.isArray(r.checks) ? r.checks : []);

    if (items.length) {
      var $tbl = $(
        '<table class="widefat striped" style="margin-top:10px">' +
          '<thead><tr><th style="width:28%">Check</th><th style="width:12%">Status</th><th>Notes</th></tr></thead>' +
          "<tbody></tbody>" +
        "</table>"
      );
      items.forEach(function (f) {
        var key = f.key || f.id || f.rule || "(rule)";
        var okTxt = (f.ok === false ? "Fail" : "Pass");
        var note = f.msg || f.note || f.why || "";
        var $tr = $("<tr/>");
        $tr.append($("<td/>").text(key));
        $tr.append($("<td/>").text(okTxt));
        $tr.append($("<td/>").text(note));
        $tbl.find("tbody").append($tr);
      });
      $wrap.append($tbl);
    } else {
      $wrap.append('<p style="margin-top:10px;">No findings/checks array present.</p>');
    }

    return $wrap;
  }

  function openReportPrintWindow(report) {
    var score = (report.summary && typeof report.summary.score !== "undefined") ? report.summary.score : "";
    var items = Array.isArray(report.findings) ? report.findings : (Array.isArray(report.checks) ? report.checks : []);
    var rows = items.map(function (f) {
      var key = f.key || f.id || f.rule || "(rule)";
      var okTxt = (f.ok === false ? "Fail" : "Pass");
      var note = f.msg || f.note || f.why || "";
      return (
        "<tr><td>" + escapeHtml(key) + "</td>" +
        "<td>" + escapeHtml(okTxt) + "</td>" +
        "<td>" + escapeHtml(note) + "</td></tr>"
      );
    }).join("");

    var docHtml = `
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Accessibility Report</title>
<style>
  body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color:#111; margin:20px; }
  h1 { font-size: 20px; margin: 0 0 10px; }
  .meta { border:1px solid #ddd; border-radius:8px; padding:10px; background:#fff; margin-bottom:12px; }
  .meta div { margin: 2px 0; }
  table { width:100%; border-collapse: collapse; }
  th, td { border:1px solid #ddd; padding:6px 8px; vertical-align: top; }
  thead th { background: #f6f7f7; }
  .muted { color:#666; }
  @media print { body { margin: 0.5in; } }
</style>
</head>
<body>
  <h1>Accessibility Report</h1>
  <div class="meta">
    <div><strong>URL:</strong> ${escapeHtml(report.url || "(n/a)")}</div>
    <div><strong>When:</strong> ${escapeHtml(report.created_at ? asDate(report.created_at) : "(n/a)")}</div>
    <div><strong>Passed:</strong> ${escapeHtml(report.summary?.passed ?? "-")} / ${escapeHtml(report.summary?.total ?? "-")}</div>
    <div><strong>Score:</strong> ${score !== "" ? escapeHtml(score + "%") : "—"}</div>
  </div>
  ${
    rows
      ? `<table>
           <thead><tr><th style="width:30%">Check</th><th style="width:12%">Status</th><th>Notes</th></tr></thead>
           <tbody>${rows}</tbody>
         </table>`
      : '<p class="muted">No findings/checks.</p>'
  }
  <script>window.onload = function(){ setTimeout(function(){ window.print(); }, 50); };</script>
</body>
</html>`;

    // 1) Try normal popup (best UX when allowed)
    var w = window.open("", "_blank", "noopener,noreferrer");
    if (w && w.document) {
      w.document.open("text/html");
      w.document.write(docHtml);
      w.document.close();
      return;
    }

    // 2) Fallback: print via hidden iframe (rarely blocked)
    try {
      var iframe = document.createElement("iframe");
      iframe.style.position = "fixed";
      iframe.style.right = "0";
      iframe.style.bottom = "0";
      iframe.style.width = "0";
      iframe.style.height = "0";
      iframe.style.border = "0";
      document.body.appendChild(iframe);
      var doc = iframe.contentDocument || iframe.contentWindow.document;
      // patch the print call so it cleans itself up
      var patched = docHtml.replace(
        "window.print()",
        "parent.setTimeout(function(){ iframe.contentWindow.focus(); iframe.contentWindow.print(); parent.document.body.removeChild(iframe); }, 50)"
      );
      doc.open("text/html");
      doc.write(patched);
      doc.close();
    } catch (err) {
      alert("Unable to open print window (pop-up blocked?). You can still use your browser’s “Print…” to save a PDF.");
    }
  }

  function makeRawDetails(obj) {
    var $details = $('<details style="margin-top:10px;"></details>');
    $details.append('<summary>Show raw JSON</summary>');
    var $pre = $("<pre/>").css({ "max-height": "420px", overflow: "auto", margin: "6px 0 0" })
      .text(JSON.stringify(obj, null, 2));
    $details.append($pre);
    return $details;
  }

  // ---------- GEMINI KEY TEST ----------
  $(document).on("click", "#vl-las-test-gemini", function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();

    var $btn = $(this);
    var $stat = $("#vl-las-gemini-test-status").text("").attr("aria-live", "polite");
    var $wrap = $("#vl-las-gemini-test-json").hide();
    var $pre = $wrap.find("pre").empty();

    var restRoot = getRestRoot();
    var nonce = getNonce();
    if (!restRoot || !nonce) {
      $stat.text("Failed: REST not initialized").css("color", "red");
      return;
    }

    $btn.prop("disabled", true);
    $stat.text("Testing…").attr("aria-busy", "true");

    var url = withNonce(joinUrl(restRoot, "gemini-test"), nonce);

    $.ajax({
      method: "POST",
      url: url,
      contentType: "application/json; charset=utf-8",
      data: JSON.stringify({}),
      timeout: 15000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
      },
    })
      .done(function (resp) {
        var ok = resp && resp.ok === true;
        var code = resp && resp.status ? " " + resp.status : "";
        $stat.text(ok ? "OK" + code : "Failed" + code).css("color", ok ? "green" : "red");
        try {
          $pre.text(JSON.stringify(resp, null, 2));
          $wrap.show();
        } catch (e) {}
      })
      .fail(function (xhr) {
        var msg = "Failed";
        try {
          var j = xhr.responseJSON || JSON.parse(xhr.responseText || "{}");
          if (j && (j.message || j.error || j.code)) msg += ": " + (j.error || j.message || j.code);
        } catch (e) {}
        $stat.text(msg + " (HTTP " + xhr.status + ")").css("color", "red");
      })
      .always(function () {
        $btn.prop("disabled", false);
        $stat.attr("aria-busy", "false");
      });
  });

  // ---------- AUDIT RUN ----------
  var auditClickLock = false;

  $(document).on("click", "#vl-las-run-audit", function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();

    var $btn = $(this);

    // If the audit button is hidden/disabled by theme, bail
    if (!$btn.is(":visible") || auditClickLock) return;

    // Make absolutely sure it's clickable
    $btn.css({ display: "inline-block", "pointer-events": "auto" });

    var $out = $("#vl-las-audit-result")
      .empty()
      .text("Running…")
      .attr("aria-live", "polite")
      .attr("aria-busy", "true");

    var html = captureHTML();
    var restRoot = getRestRoot();

    // IMPORTANT: read nonce from the button first; fallback to global
    var dataNonce = $btn.attr("data-nonce") || $btn.data("nonce") || "";
    var nonce = dataNonce || getNonce();

    if (!restRoot || !nonce) {
      $out.text("Audit request error: REST not initialized (missing nonce or root)").attr("aria-busy", "false");
      return;
    }

    // Determine endpoint path from data attribute (default to audit2)
    var restPath = $btn.attr("data-rest-path") || $btn.data("restPath") || "audit2";

    // lock to prevent double submissions for ~2s
    auditClickLock = true;
    $btn.prop("disabled", true);
    setTimeout(function () {
      auditClickLock = false;
      $btn.prop("disabled", false);
    }, 2000);

    var payload = { url: window.location.origin + "/", html: html };
    var url = withNonce(joinUrl(restRoot, restPath), nonce); // add nonce as query param too

    $.ajax({
      method: "POST",
      url: url,
      contentType: "application/json; charset=utf-8",
      data: JSON.stringify(payload),
      timeout: 30000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      },
    })
      .done(function (resp) {
        var $box = $('<div class="vl-las-audit-wrap"/>');

        if (resp && resp.ok && resp.report) {
          var r = resp.report;

          // header
          var $h = $('<div class="vl-las-audit-head"/>');
          $h.append(
            $("<div/>").html(
              "<strong>Result:</strong> " +
                (typeof r.score !== "undefined" ? "score " + r.score : "OK")
            )
          );
          if (r.url) $h.append($("<div/>").html("<strong>URL:</strong> " + r.url));
          if (r.created_at) $h.append($("<div/>").html("<strong>When:</strong> " + asDate(r.created_at)));
          $box.append($h);

          // findings/checks (if present)
          var items = Array.isArray(r.findings) ? r.findings : (Array.isArray(r.checks) ? r.checks : []);
          if (items.length) {
            var $tbl = $(
              '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>Check</th><th>Status</th><th>Notes</th></tr></thead><tbody></tbody></table>'
            );
            items.forEach(function (f) {
              var tr = $("<tr/>");
              tr.append($("<td/>").text(f.key || f.id || f.rule || "(rule)"));
              tr.append($("<td/>").text(f.ok === false ? "Fail" : "Pass"));
              tr.append($("<td/>").text(f.msg || f.note || f.why || ""));
              $tbl.find("tbody").append(tr);
            });
            $box.append($tbl);
          }

          // actions
          var $dl = $('<p style="margin-top:10px;"></p>');
          var jsonText = JSON.stringify(r, null, 2);
          $('<button type="button" class="button">Download JSON</button>')
            .on("click", function () {
              var id = r.id || r.report_id || Date.now();
              downloadBlob(jsonText, "vl-las-report-" + id + ".json");
            })
            .appendTo($dl);
          $dl.append(" ");
          $('<button type="button" class="button">Download PDF</button>')
            .on("click", function () {
              openReportPrintWindow(r);
            })
            .appendTo($dl);
          $box.append($dl);

          // optional raw JSON toggle (respects settings checkbox)
          var showRaw = !!$("#vl_las_audit_show_json").is(":checked");
          if (showRaw) {
            $box.append(
              $("<pre/>")
                .css({ "margin-top": "8px", "max-height": "320px", overflow: "auto" })
                .text(jsonText)
            );
          }
        } else {
          // fallback raw
          $box.append($("<pre/>").text(JSON.stringify(resp || { ok: false, error: "Empty response" }, null, 2)));
        }

        $("#vl-las-audit-result").empty().append($box);

        // refresh past reports if container exists
        if ($("#vl-las-audit-list").length) {
          loadReports();
        }
      })
      .fail(function (xhr) {
        var msg = "Audit request error";
        try {
          var j = xhr.responseJSON || JSON.parse(xhr.responseText || "{}");
          if (j && (j.message || j.code || j.error)) msg += ": " + (j.error || j.message || j.code);
        } catch (e) {}
        $("#vl-las-audit-result").text(msg + " (HTTP " + xhr.status + ")");
      })
      .always(function () {
        $out.attr("aria-busy", "false");
      });
  });

  // ---------- PAST REPORTS LIST ----------
  function renderPrettyDrawer(data) {
    // Normalize: endpoint may return full row with 'report' or just the report object
    var r = data && data.report ? data.report : data;
    var $wrap = $('<div class="vl-las-report-expanded-inner"/>');
    $wrap.append(renderPrettyReport(r));

    // action bar in drawer
    var $bar = $('<p style="margin:10px 0 0;"></p>');
    var jsonText = JSON.stringify(r, null, 2);
    $('<button type="button" class="button">Download JSON</button>')
      .on("click", function () {
        var id = r.id || r.report_id || Date.now();
        downloadBlob(jsonText, "vl-las-report-" + id + ".json");
      })
      .appendTo($bar);
    $bar.append(" ");
    $('<button type="button" class="button">Download PDF</button>')
      .on("click", function () {
        openReportPrintWindow(r);
      })
      .appendTo($bar);

    $wrap.append($bar);
    // collapsible raw JSON for nerds
    $wrap.append(makeRawDetails(r));

    return $wrap;
  }

  function renderReports(list) {
    var $host = $("#vl-las-audit-list");
    if (!$host.length) return;
    $host.empty();

    if (!list || !list.length) {
      $host.append("<p>No reports yet.</p>");
      return;
    }

    var $tbl = $(
      '<table class="widefat striped"><thead><tr>' +
        "<th>ID</th><th>Date</th><th>URL</th><th>Issues</th><th>Actions</th>" +
        "</tr></thead><tbody></tbody></table>"
    );

    list.forEach(function (it) {
      var id = it.id || it.report_id || "";
      var date = asDate(it.created_at || it.date || "");
      var url = it.url || "";
      var issues =
        typeof it.issues !== "undefined"
          ? it.issues
          : it.counts && typeof it.counts.fail !== "undefined"
          ? it.counts.fail
          : it.summary &&
            typeof it.summary.passed !== "undefined" &&
            typeof it.summary.total !== "undefined"
          ? it.summary.total - it.summary.passed
          : "";

      var $tr = $("<tr/>");
      $tr.append($("<td/>").text(id));
      $tr.append($("<td/>").text(date));
      $tr.append($("<td/>").text(url));
      $tr.append($("<td/>").text(issues));

      var $act = $("<td/>");

      // View (pretty drawer)
      var $view = $('<button type="button" class="button">View</button>').on("click", function () {
        // lazy fetch single
        fetchReport(id, function (ok, data) {
          if (!ok) return;
          // collapse any existing expanded row next to this one
          $tr.next(".vl-las-report-expanded").remove();

          var $row = $("<tr class='vl-las-report-expanded'><td colspan='5'></td></tr>");
          var $cell = $row.find("td");

          // Pretty
          $cell.append(renderPrettyDrawer(data));

          $tr.after($row);
        });
      });

      // Download JSON
      var $dl = $('<button type="button" class="button">Download JSON</button>').on("click", function () {
        downloadReport(id);
      });

      // Download PDF (client-side)
      var $pdf = $('<button type="button" class="button">Download PDF</button>').on("click", function () {
        // We need the full report object to render PDF—fetch it, then print.
        fetchReport(id, function (ok, data) {
          if (!ok) return;
          var r = data && data.report ? data.report : data;
          openReportPrintWindow(r);
        });
      });

      $act.append($view).append(" ").append($dl).append(" ").append($pdf);
      $tr.append($act);
      $tbl.find("tbody").append($tr);
    });

    $host.append($tbl);
  }

  function fetchReport(id, cb) {
    var restRoot = getRestRoot();
    var nonce = getNonce();
    if (!restRoot || !nonce) {
      cb && cb(false);
      return;
    }
    var url = withNonce(joinUrl(restRoot, "report/" + encodeURIComponent(id)), nonce);

    $.ajax({
      method: "GET",
      url: url,
      timeout: 15000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
      },
    })
      .done(function (resp) {
        cb && cb(true, resp);
      })
      .fail(function () {
        cb && cb(false);
      });
  }

  function downloadReport(id) {
    var restRoot = getRestRoot();
    var nonce = getNonce();
    if (!restRoot || !nonce) {
      alert("REST not initialized");
      return;
    }
    var url = withNonce(joinUrl(restRoot, "report/" + encodeURIComponent(id) + "/download"), nonce);

    $.ajax({
      method: "GET",
      url: url,
      timeout: 15000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
      },
    })
      .done(function (respText, status, xhr) {
        // If server set Content-Disposition with filename, try to respect it
        var cd = xhr.getResponseHeader("Content-Disposition") || "";
        var match = /filename="?([^"]+)"?/i.exec(cd);
        var name = match ? match[1] : "vl-las-report-" + id + ".json";

        // Normalize payload to string
        var text;
        if (typeof respText === "string") {
          text = respText;
        } else {
          try {
            text = JSON.stringify(respText, null, 2);
          } catch (e) {
            text = String(respText);
          }
        }
        downloadBlob(text, name);
      })
      .fail(function (xhr) {
        alert("Download failed (HTTP " + xhr.status + ")");
      });
  }

  function loadReports() {
    var $host = $("#vl-las-audit-list");
    if (!$host.length) return;

    var restRoot = getRestRoot();
    var nonce = getNonce();
    if (!restRoot || !nonce) {
      $host.html("<p>REST not initialized.</p>");
      return;
    }

    $host.html("<p>Loading reports…</p>");

    var url = withNonce(joinUrl(restRoot, "reports?per_page=20"), nonce);

    $.ajax({
      method: "GET",
      url: url,
      timeout: 15000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
      },
    })
      .done(function (resp) {
        // Accept { ok:true, items:[...] } or a plain array
        var list = resp && resp.ok && Array.isArray(resp.items) ? resp.items : Array.isArray(resp) ? resp : [];
        renderReports(list);
      })
      .fail(function (xhr) {
        $host.html("<p>Failed to load reports (HTTP " + xhr.status + ").</p>");
      });
  }

  // ---------- SOC 2 AUTOMATION ----------
  function setSoc2StatusText(text, isError) {
    var $status = $("#vl-las-soc2-status");
    if (!$status.length) return;
    $status.text(text || "");
    $status.css("color", isError ? "red" : "");
  }

  function updateSoc2Raw(text) {
    var $raw = $("#vl-las-soc2-raw");
    if (!$raw.length) return;
    if (!text) {
      $raw.hide();
      $raw.find("pre").text("");
      return;
    }
    $raw.show();
    $raw.find("pre").text(text);
  }

  function cleanText(value) {
    if (value === null || value === undefined) return "";
    return String(value).trim();
  }

  function cleanArray(values) {
    if (!Array.isArray(values)) return [];
    return values.reduce(function (acc, item) {
      var text = cleanText(item);
      if (text) acc.push(text);
      return acc;
    }, []);
  }

  function hasReportData(report) {
    if (!report) return false;
    if (Array.isArray(report)) {
      return report.length > 0;
    }
    if (typeof report === "object") {
      return Object.keys(report).length > 0;
    }
    return !!report;
  }

  function pushDetailRow(parts, label, value, joiner) {
    var text = Array.isArray(value) ? cleanArray(value).join(joiner || ", ") : cleanText(value);
    if (!text) return;
    parts.push('<p><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(text) + "</p>");
  }

  function buildListItem(label, value, joiner) {
    var text = Array.isArray(value) ? cleanArray(value).join(joiner || ", ") : cleanText(value);
    if (!text) return "";
    return '<li><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(text) + '</li>';
  }

  function buildListSection(title, items) {
    var filtered = items.filter(function (item) { return !!item; });
    if (!filtered.length) return "";
    return '<h4>' + escapeHtml(title) + '</h4><ul>' + filtered.join("") + '</ul>';
  }

  function formatObservationPeriod(period) {
    if (!period) return "";
    var start = cleanText(period.start);
    var end = cleanText(period.end);
    if (start && end) {
      return start + ' – ' + end;
    }
    return start || end || "";
  }

  function formatArtifactLabel(label) {
    var text = cleanText(label).replace(/[_-]+/g, ' ');
    text = text.replace(/\s+/g, ' ').trim();
    if (!text) return "";
    return text.split(' ').map(function (part) {
      if (!part) return "";
      return part.charAt(0).toUpperCase() + part.slice(1);
    }).join(' ');
  }

  function buildSoc2Header(meta, trust, period, auditors, summary) {
    var header = [];
    header.push('<h3 style="margin-top:0;">' + escapeHtml(cleanText(meta.type) || "SOC 2 Type II") + '</h3>');
    var generated = meta.generated_at ? asDate(meta.generated_at) : "";
    pushDetailRow(header, "Generated", generated);
    pushDetailRow(header, "Trust Services Criteria", trust);
    pushDetailRow(header, "Observation Period", formatObservationPeriod(period));
    pushDetailRow(header, "Analysis Engine", meta.analysis_engine);
    pushDetailRow(header, "Auditor Status", auditors.status);
    pushDetailRow(header, "Auditor Opinion", auditors.opinion);
    if (summary) {
      header.push('<h4>Executive Summary</h4>');
      header.push('<p>' + escapeHtml(cleanText(summary)) + '</p>');
    }
    return header.join("");
  }

  function buildSystemOverviewSection(system) {
    var overview = [];
    var company = system.company_overview || {};
    var missionLine = cleanText(company.mission);
    var structure = cleanText(company.structure);
    if (structure) {
      missionLine += (missionLine ? ' — ' : '') + structure;
    }
    overview.push(buildListItem('Mission & Structure', missionLine));
    overview.push(buildListItem('Services', system.services_in_scope));
    overview.push(buildListItem('Infrastructure', system.infrastructure, '; '));
    overview.push(buildListItem('Software', system.software_components, '; '));
    overview.push(buildListItem('Data Flows', system.data_flows, '; '));
    overview.push(buildListItem('Personnel', system.personnel, '; '));
    overview.push(buildListItem('Subservice Organizations', system.subservice_organizations, '; '));
    overview.push(buildListItem('Control Boundaries', system.control_boundaries, '; '));
    overview.push(buildListItem('BCP / DR', system.business_continuity, '; '));
    return buildListSection('System Overview', overview);
  }

  function buildControlMatrixSection(matrix) {
    if (!Array.isArray(matrix) || !matrix.length) return "";
    var rows = [];
    matrix.slice(0, 6).forEach(function (row) {
      var domain = cleanText(row.domain || row.label);
      var status = cleanText(row.status);
      var tsc = Array.isArray(row.aligned_tsc) ? cleanArray(row.aligned_tsc).join(', ') : cleanText(row.aligned_tsc);
      rows.push('<tr><td>' + escapeHtml(domain) + '</td><td>' + escapeHtml(status) + '</td><td>' + escapeHtml(tsc) + '</td></tr>');
    });
    if (!rows.length) return "";
    return '<h4>Control Matrix Highlights</h4>' +
      '<table class="widefat striped" style="margin-top:8px"><thead><tr><th>Domain</th><th>Status</th><th>Aligned TSC</th></tr></thead><tbody>' +
      rows.join("") + '</tbody></table>';
  }

  function buildTestingSection(tests) {
    var procedures = cleanArray(tests && tests.procedures);
    var evidence = cleanArray(tests && tests.evidence_summary);
    var items = [];
    procedures.slice(0, 6).forEach(function (proc) {
      items.push('<li>' + escapeHtml(proc) + '</li>');
    });
    if (evidence.length) {
      items.push('<li><strong>Evidence:</strong> ' + escapeHtml(evidence.join('; ')) + '</li>');
    }
    return buildListSection('Testing & Evidence', items);
  }

  function buildRiskSection(risk) {
    var items = [];
    var gaps = cleanArray(risk && risk.gaps);
    var remediation = cleanArray(risk && risk.remediation);
    var matrix = cleanArray(risk && risk.matrix);
    if (gaps.length) {
      items.push('<li><strong>Gaps:</strong> ' + escapeHtml(gaps.join('; ')) + '</li>');
    }
    if (remediation.length) {
      items.push('<li><strong>Remediation:</strong> ' + escapeHtml(remediation.join('; ')) + '</li>');
    }
    if (matrix.length) {
      items.push('<li><strong>Risk Matrix:</strong> ' + escapeHtml(matrix.join('; ')) + '</li>');
    }
    return buildListSection('Risk & Remediation', items);
  }

  function buildArtifactsSection(artifacts) {
    if (!artifacts || typeof artifacts !== 'object') return "";
    var keys = Object.keys(artifacts);
    if (!keys.length) return "";
    keys.sort();
    var items = [];
    keys.forEach(function (key) {
      var value = artifacts[key];
      var label = formatArtifactLabel(key);
      if (!label) return;
      if (Array.isArray(value)) {
        var joined = cleanArray(value).join('; ');
        if (joined) {
          items.push('<li><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(joined) + '</li>');
        }
      } else {
        var text = cleanText(value);
        if (text) {
          items.push('<li><strong>' + escapeHtml(label) + ':</strong> ' + escapeHtml(text) + '</li>');
        }
      }
    });
    return buildListSection('Supporting Artifacts', items);
  }

  function renderSoc2Report(report) {
    var $host = $("#vl-las-soc2-report");
    if (!$host.length) return;

    var emptyObject = report && typeof report === "object" && !Array.isArray(report) && Object.keys(report).length === 0;

    if (!report || emptyObject) {
      $host.html('<p class="description">Run the sync to generate your SOC 2 package.</p>');
      updateSoc2Raw("");
      window.VLLAS = window.VLLAS || {};
      window.VLLAS.soc2Markdown = "";
      return;
    }

    var meta = report.meta || {};
    var trust = cleanArray(report.trust_services && report.trust_services.selected);
    var tests = report.control_tests || {};
    var period = tests.observation_period || {};
    var auditors = report.auditors || {};
    var summary = report.documents && report.documents.executive_summary ? report.documents.executive_summary : "";
    var markdown = report.documents && report.documents.markdown ? report.documents.markdown : "";

    window.VLLAS = window.VLLAS || {};
    window.VLLAS.soc2Markdown = markdown;

    var htmlParts = [];
    htmlParts.push('<div class="vl-las-soc2-card" style="border:1px solid #ccd0d4;border-radius:6px;padding:12px;background:#fff;">');
    htmlParts.push(buildSoc2Header(meta, trust, period, auditors, summary));

    var systemSection = buildSystemOverviewSection(report.system_description || {});
    if (systemSection) htmlParts.push(systemSection);

    var matrix = report.control_environment && Array.isArray(report.control_environment.control_matrix)
      ? report.control_environment.control_matrix
      : [];
    var matrixSection = buildControlMatrixSection(matrix);
    if (matrixSection) htmlParts.push(matrixSection);

    var testingSection = buildTestingSection(tests);
    if (testingSection) htmlParts.push(testingSection);

    var riskSection = buildRiskSection(report.risk_assessment || {});
    if (riskSection) htmlParts.push(riskSection);

    var artifactsSection = buildArtifactsSection(report.supporting_artifacts || {});
    if (artifactsSection) htmlParts.push(artifactsSection);

    htmlParts.push('</div>');
    $host.html(htmlParts.join(""));

    updateSoc2Raw(JSON.stringify(report, null, 2));
  }

  function setSoc2Bundle(bundle) {
    window.VLLAS = window.VLLAS || {};
    window.VLLAS.soc2Current = bundle || {};

    var report = bundle && bundle.report ? bundle.report : null;
    var meta = bundle && bundle.meta ? bundle.meta : {};
    var enabledFlag = null;
    if (bundle && typeof bundle.enabled !== "undefined") {
      enabledFlag = !!bundle.enabled;
    }
    var hasReport = hasReportData(report);

    var runBtn = document.getElementById("vl-las-soc2-run");
    if (runBtn) {
      if (enabledFlag === false) {
        runBtn.disabled = true;
        runBtn.setAttribute("aria-disabled", "true");
        runBtn.classList.add("disabled");
      } else {
        runBtn.disabled = false;
        runBtn.removeAttribute("aria-disabled");
        runBtn.classList.remove("disabled");
      }
    }

    $("#vl-las-soc2-download-json, #vl-las-soc2-download-markdown").prop("disabled", !hasReport);

    if (enabledFlag === false) {
      setSoc2StatusText(
        "SOC 2 automation is disabled. Enable it above and click \"Save Changes\" before running a sync.",
        false
      );
    } else if (hasReport) {
      var trustList = cleanArray(Array.isArray(meta.trust_services) ? meta.trust_services : []);
      var trustText = trustList.length ? trustList.join(", ") : "baseline criteria";
      var generated = meta.generated_at ? asDate(meta.generated_at) : "";
      setSoc2StatusText("Last generated on " + generated + " covering " + trustText + ".", false);
    } else {
      setSoc2StatusText("No SOC 2 report generated yet.", false);
    }

    renderSoc2Report(report);
  }

  function fetchSoc2Report(cb) {
    var btn = document.getElementById("vl-las-soc2-run");
    if (!btn) {
      cb && cb(false);
      return;
    }

    var restRoot = btn.getAttribute("data-rest-root") || getRestRoot();
    var nonce = btn.getAttribute("data-nonce") || getNonce();
    if (!restRoot || !nonce) {
      cb && cb(false);
      return;
    }

    var url = withNonce(joinUrl(restRoot, "soc2/report"), nonce);

    $.ajax({
      method: "GET",
      url: url,
      timeout: 30000,
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
      },
    })
      .done(function (resp) {
        if (resp && resp.ok) {
          setSoc2Bundle(resp);
          cb && cb(true, resp);
        } else {
          if (resp && (resp.error || resp.message)) {
            setSoc2StatusText(resp.error || resp.message, true);
          }
          cb && cb(false, resp);
        }
      })
      .fail(function (xhr) {
        var status = xhr && typeof xhr.status !== "undefined" ? xhr.status : 0;
        var message = "Unable to reach the SOC 2 endpoint.";
        if (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) {
          message += " " + (xhr.responseJSON.error || xhr.responseJSON.message);
        }
        if (status) {
          message += " (HTTP " + status + ")";
        }
        setSoc2StatusText(message, true);
        cb && cb(false, xhr);
      });
  }

  function bootstrapSoc2() {
    if (!document.getElementById("vl-las-soc2-run")) return;

    if (window.VLLAS && window.VLLAS.soc2Initial) {
      setSoc2Bundle(window.VLLAS.soc2Initial);
    } else {
      setSoc2Bundle({});
    }

    fetchSoc2Report();
  }

  $(document).on("click", "#vl-las-soc2-run", function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();

    var btn = this;
    var restRoot = btn.getAttribute("data-rest-root") || getRestRoot();
    var nonce = btn.getAttribute("data-nonce") || getNonce();
    if (!restRoot || !nonce) {
      setSoc2StatusText("REST not initialized for SOC 2 automation.", true);
      return;
    }

    setSoc2StatusText("Syncing with VL Hub…", false);
    btn.disabled = true;

    var url = withNonce(joinUrl(restRoot, "soc2/run"), nonce);

    $.ajax({
      method: "POST",
      url: url,
      timeout: 60000,
      contentType: "application/json; charset=utf-8",
      data: JSON.stringify({}),
      beforeSend: function (xhr) {
        xhr.setRequestHeader("X-WP-Nonce", nonce);
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");
      },
    })
      .done(function (resp) {
        if (resp && resp.ok && resp.report) {
          setSoc2Bundle(resp);
          setSoc2StatusText("SOC 2 report generated successfully.", false);
        } else {
          var err = resp && (resp.error || resp.message) ? resp.error || resp.message : "Unexpected response";
          setSoc2StatusText("SOC 2 sync failed: " + err, true);
        }
      })
      .fail(function (xhr) {
        var msg = "SOC 2 sync failed";
        if (xhr && xhr.responseJSON && (xhr.responseJSON.error || xhr.responseJSON.message)) {
          msg += ": " + (xhr.responseJSON.error || xhr.responseJSON.message);
        }
        setSoc2StatusText(msg + " (HTTP " + xhr.status + ")", true);
      })
      .always(function () {
        btn.disabled = false;
      });
  });

  $(document).on("click", "#vl-las-soc2-download-json", function (e) {
    e.preventDefault();
    var bundle = window.VLLAS && window.VLLAS.soc2Current;
    var report = bundle && bundle.report ? bundle.report : null;
    if (!report) return;
    var name = "vl-las-soc2-report.json";
    downloadBlob(JSON.stringify(report, null, 2), name);
  });

  $(document).on("click", "#vl-las-soc2-download-markdown", function (e) {
    e.preventDefault();
    var md = window.VLLAS && window.VLLAS.soc2Markdown;
    if (!md) {
      alert("Markdown package not available yet. Run the sync first.");
      return;
    }
    downloadBlob(md, "vl-las-soc2-report.md");
  });

  $(document).on("change", 'input[name="vl_las_soc2_enabled"]', function () {
    var btn = document.getElementById("vl-las-soc2-run");
    if (!btn) return;

    var bundle = window.VLLAS && window.VLLAS.soc2Current;
    var report = bundle && bundle.report ? bundle.report : null;
    var hasReport = hasReportData(report);

    if (this.checked) {
      btn.disabled = false;
      btn.removeAttribute("aria-disabled");
      btn.classList.remove("disabled");
      if (!hasReport) {
        setSoc2StatusText("Save changes, then run a sync to pull the latest SOC 2 package.", false);
      }
    } else {
      btn.disabled = true;
      btn.setAttribute("aria-disabled", "true");
      btn.classList.add("disabled");
      setSoc2StatusText(
        "SOC 2 automation is disabled. Enable it above and click \"Save Changes\" before running a sync.",
        false
      );
    }
  });

  // ---------- boot ----------
  $(function () {
    if (onSettingsPage()) {
      // Force the audit button visible if some stylesheet hid it
      $("#vl-las-run-audit").css({ display: "inline-block", "pointer-events": "auto" });

      // Load past reports list on page load
      if ($("#vl-las-audit-list").length) {
        loadReports();
      }

      bootstrapSoc2();
    }
  });
})(jQuery);
