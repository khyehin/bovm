// public/assets/js/sign_pad.js
(function () {
  function _resizeKeep(canvas, ctx, hasDrawnRef) {
    const rect = canvas.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;

    // backup old bitmap
    const tmp = document.createElement("canvas");
    tmp.width = canvas.width;
    tmp.height = canvas.height;
    tmp.getContext("2d").drawImage(canvas, 0, 0);

    // set new bitmap size
    canvas.width = Math.max(1, Math.floor(rect.width * dpr));
    canvas.height = Math.max(1, Math.floor(rect.height * dpr));

    // draw in CSS pixels
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    ctx.clearRect(0, 0, rect.width, rect.height);
    if (tmp.width > 0 && tmp.height > 0 && hasDrawnRef.value) {
      ctx.drawImage(tmp, 0, 0, tmp.width, tmp.height, 0, 0, rect.width, rect.height);
    }
  }

  window.VMSignPad = function (canvasId, clearBtnId, opt) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) return null;

    const ctx = canvas.getContext("2d");
    const options = opt || {};
    const lineWidth = options.lineWidth || 2;
    const strokeStyle = options.strokeStyle || "#111827";

    let drawing = false;
    let lastX = 0;
    let lastY = 0;
    const hasDrawnRef = { value: false };

    function getPos(e) {
      const rect = canvas.getBoundingClientRect();
      if (e.touches && e.touches.length > 0) {
        return { x: e.touches[0].clientX - rect.left, y: e.touches[0].clientY - rect.top };
      }
      return { x: e.clientX - rect.left, y: e.clientY - rect.top };
    }

    function start(e) {
      e.preventDefault();
      drawing = true;
      const p = getPos(e);
      lastX = p.x;
      lastY = p.y;
    }

    function move(e) {
      if (!drawing) return;
      e.preventDefault();
      const p = getPos(e);

      ctx.strokeStyle = strokeStyle;
      ctx.lineWidth = lineWidth;
      ctx.lineCap = "round";

      ctx.beginPath();
      ctx.moveTo(lastX, lastY);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();

      lastX = p.x;
      lastY = p.y;
      hasDrawnRef.value = true;
    }

    function end(e) {
      if (!drawing) return;
      e.preventDefault();
      drawing = false;
    }

    function resize() {
      _resizeKeep(canvas, ctx, hasDrawnRef);
    }

    // init + resize listener
    resize();
    window.addEventListener("resize", resize);

    // mouse
    canvas.addEventListener("mousedown", start);
    canvas.addEventListener("mousemove", move);
    canvas.addEventListener("mouseup", end);
    canvas.addEventListener("mouseleave", end);

    // touch
    canvas.addEventListener("touchstart", start, { passive: false });
    canvas.addEventListener("touchmove", move, { passive: false });
    canvas.addEventListener("touchend", end, { passive: false });
    canvas.addEventListener("touchcancel", end, { passive: false });

    // clear
    const clearBtn = clearBtnId ? document.getElementById(clearBtnId) : null;
    if (clearBtn) {
      clearBtn.addEventListener("click", function () {
        const rect = canvas.getBoundingClientRect();
        ctx.clearRect(0, 0, rect.width, rect.height);
        hasDrawnRef.value = false;
      });
    }

    return {
      hasDrawn: function () {
        return !!hasDrawnRef.value;
      },
      getImage: function () {
        return hasDrawnRef.value ? canvas.toDataURL("image/png") : "";
      },
    };
  };
})();
