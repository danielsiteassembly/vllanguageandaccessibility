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
    return !!document.getElementById("vl-las-audit-result") || !!document.getElementById("vl-las-audit-list");
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

  // ---------- boot ----------
  $(function () {
    if (onSettingsPage()) {
      // Force the audit button visible if some stylesheet hid it
      $("#vl-las-run-audit").css({ display: "inline-block", "pointer-events": "auto" });

      // Load past reports list on page load
      if ($("#vl-las-audit-list").length) {
        loadReports();
      }
    }
  });
})(jQuery);
