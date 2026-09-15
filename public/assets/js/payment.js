/*
 * Reusable payment modal behaviour.
 *
 * The modal HTML is supplied by app/Views/components/payment/modal.php.
 * Pages must load this file once and call bukaPaymentModal(options) only
 * after the modal markup has been placed in #paymentModalContainer.
 */

(function (window, document) {
  "use strict";

  /*
  |--------------------------------------------------------------------------
  | STATE
  |--------------------------------------------------------------------------
  */

  const state = {
    mode: null,
    transaksiId: null,
    total: 0,
    sisa: 0,
    cart: [],
    kasirPayload: null,

    /*
     * payment
     * tagihan-lunasi
     */
    existingFlow: "payment",

    /*
     * Untuk pembayaran non-cash pada transaksi existing.
     */
    allowPartialNonCash: false,

    /*
     * Nilai DP yang sedang dipilih.
     */
    dpAmount: 0,

    /*
     * Mencegah submit pembayaran ganda.
     */
    submitting: false,

    /*
     |--------------------------------------------------------------------------
     | STATE KONFIRMASI NON-TUNAI
     |--------------------------------------------------------------------------
     */

    confirmMetode: null,
    confirmAmount: 0,
    confirmExtras: null,
  };

  /*
  |--------------------------------------------------------------------------
  | CACHE DAFTAR KASIR (untuk dropdown "Kasir Penerima" saat backdate)
  |--------------------------------------------------------------------------
  */

  let kasirListCache = null;
  let kasirListPromise = null;


  /*
  |--------------------------------------------------------------------------
  | SELECTORS
  |--------------------------------------------------------------------------
  */

  const selectors = {
    main: "#paymentModal",
    cash: "#paymentCashModal",
    dp: "#paymentDpModal",
    confirm: "#paymentConfirmModal",
  };


  /*
  |--------------------------------------------------------------------------
  | HELPER ELEMENT
  |--------------------------------------------------------------------------
  */

  function element(selector) {
    return document.querySelector(selector);
  }


  /*
  |--------------------------------------------------------------------------
  | FORMAT RUPIAH
  |--------------------------------------------------------------------------
  */

  function formatRupiah(value) {
    return (
      "Rp " +
      new Intl.NumberFormat("id-ID").format(
        Number(value) || 0
      )
    );
  }


  /*
  |--------------------------------------------------------------------------
  | NOTIFICATION
  |--------------------------------------------------------------------------
  */

  function notify(message, type) {
    if (typeof window.showToast === "function") {
      window.showToast(message, type);
      return;
    }

    window.alert(message);
  }


  /*
  |--------------------------------------------------------------------------
  | BOOTSTRAP MODAL INSTANCE
  |--------------------------------------------------------------------------
  */

  function modalInstance(selector) {
    const modalElement =
      element(selector);

    if (
      !modalElement ||
      !window.bootstrap ||
      !window.bootstrap.Modal
    ) {
      return null;
    }

    return window.bootstrap.Modal.getOrCreateInstance(
      modalElement
    );
  }


  /*
  |--------------------------------------------------------------------------
  | SHOW MODAL
  |--------------------------------------------------------------------------
  */

  function showModal(selector) {
    const instance =
      modalInstance(selector);

    if (instance) {
      instance.show();
    }
  }


  /*
  |--------------------------------------------------------------------------
  | HIDE MODAL
  |--------------------------------------------------------------------------
  */

  function hideModal(selector) {
    const instance =
      modalInstance(selector);

    if (instance) {
      instance.hide();
    }
  }


  /*
  |--------------------------------------------------------------------------
  | CEK KETERSEDIAAN SEMUA MODAL
  |--------------------------------------------------------------------------
  */

  function modalIsAvailable() {
    return Boolean(
      element(selectors.main) &&
      element(selectors.cash) &&
      element(selectors.dp) &&
      element(selectors.confirm)
    );
  }


  /*
  |--------------------------------------------------------------------------
  | LOAD MODAL HTML
  |--------------------------------------------------------------------------
  */

  let modalLoadPromise = null;


  function ensureModalLoaded() {

    /*
     * Jika modal sudah tersedia,
     * tidak perlu fetch ulang.
     */
    if (modalIsAvailable()) {
      return Promise.resolve();
    }


    /*
     * Hindari dua proses fetch
     * berjalan bersamaan.
     */
    if (modalLoadPromise) {
      return modalLoadPromise;
    }


    const container =
      element("#paymentModalContainer");

    const modalUrl =
      config().modalUrl;


    if (!container || !modalUrl) {

      return Promise.reject(
        new Error(
          "Container atau URL modal pembayaran belum dikonfigurasi."
        )
      );
    }


    modalLoadPromise =
      window
        .fetch(
          modalUrl,
          {
            credentials: "same-origin",
          }
        )

        .then(
          (response) => {

            if (!response.ok) {

              throw new Error(
                "Gagal memuat modal pembayaran."
              );
            }

            return response.text();
          }
        )

        .then(
          (html) => {

            /*
             * Jangan duplikasi modal.
             */
            if (!modalIsAvailable()) {
              container.innerHTML = html;
            }


            /*
             * Pastikan seluruh modal
             * benar-benar tersedia.
             */
            if (!modalIsAvailable()) {

              throw new Error(
                "HTML modal pembayaran tidak lengkap."
              );
            }
          }
        )

        .finally(
          () => {

            modalLoadPromise = null;
          }
        );


    return modalLoadPromise;
  }


  /*
  |--------------------------------------------------------------------------
  | NUMBER FROM INPUT
  |--------------------------------------------------------------------------
  */

  function numberFromInput(input) {

    if (!input) {
      return 0;
    }

    return (
      parseInt(
        String(input.value)
          .replace(/[^0-9]/g, ""),
        10
      ) || 0
    );
  }


  /*
  |--------------------------------------------------------------------------
  | FORMAT INPUT TUNAI
  |--------------------------------------------------------------------------
  */

  function formatCashInput(input) {

    const amount =
      numberFromInput(input);

    input.value =
      amount > 0
        ? amount.toLocaleString("id-ID")
        : "";

    updateCashChange();
  }


  /*
  |--------------------------------------------------------------------------
  | RESET TUNAI
  |--------------------------------------------------------------------------
  */

  function resetCash() {

    const input =
      element("#paymentCashReceived");

    const changeContainer =
      element(
        "#paymentChangeContainer"
      );

    const change =
      element("#paymentChange");

    const warning =
      element("#paymentCashWarning");

    const submit =
      element("#paymentSubmitCash");


    if (input) {
      input.value = "";
    }

    if (changeContainer) {
      changeContainer.style.display =
        "none";
    }

    if (change) {
      change.textContent =
        "Rp 0";
    }

    if (warning) {
      warning.style.display =
        "none";
    }

    if (submit) {
      submit.disabled = true;
    }

    resetBackdateSection(
      "Cash"
    );
  }


  /*
  |--------------------------------------------------------------------------
  | HITUNG KEMBALIAN
  |--------------------------------------------------------------------------
  */

  function updateCashChange() {

    const received =
      numberFromInput(
        element(
          "#paymentCashReceived"
        )
      );


    const due =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    const changeContainer =
      element(
        "#paymentChangeContainer"
      );

    const change =
      element("#paymentChange");

    const warning =
      element("#paymentCashWarning");

    const submit =
      element("#paymentSubmitCash");


    /*
     * Belum ada uang.
     */
    if (received <= 0) {

      if (changeContainer) {
        changeContainer.style.display =
          "none";
      }

      if (warning) {
        warning.style.display =
          "none";
      }

      if (submit) {
        submit.disabled =
          true;
      }

      return;
    }


    /*
     * Uang kurang.
     */
    if (received < due) {

      if (changeContainer) {
        changeContainer.style.display =
          "none";
      }

      if (warning) {
        warning.style.display =
          "block";
      }

      if (submit) {
        submit.disabled =
          true;
      }

      return;
    }


    /*
     * Uang cukup.
     */
    if (change) {

      change.textContent =
        formatRupiah(
          received - due
        );
    }

    if (changeContainer) {
      changeContainer.style.display =
        "block";
    }

    if (warning) {
      warning.style.display =
        "none";
    }

    if (submit) {
      submit.disabled =
        false;
    }
  }


  /*
  |--------------------------------------------------------------------------
  | TAMBAH PECAHAN UANG
  |--------------------------------------------------------------------------
  */

  function addCash(amount) {

    const input =
      element("#paymentCashReceived");

    if (!input) {
      return;
    }


    input.value =
      (
        numberFromInput(input) +
        amount
      ).toLocaleString("id-ID");


    updateCashChange();
  }


  /*
  |--------------------------------------------------------------------------
  | UANG PAS
  |--------------------------------------------------------------------------
  */

  function setExactCash() {

    const input =
      element("#paymentCashReceived");

    if (!input) {
      return;
    }


    const due =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    input.value =
      due.toLocaleString("id-ID");


    updateCashChange();
  }


  /*
  |--------------------------------------------------------------------------
  | FORMAT INPUT NOMINAL DP
  |--------------------------------------------------------------------------
  */

  function formatDpNominalInput(input) {

    const amount =
      numberFromInput(input);

    input.value =
      amount > 0
        ? amount.toLocaleString("id-ID")
        : "";

    updateDpSummary();
  }


  /*
  |--------------------------------------------------------------------------
  | RESET DP
  |--------------------------------------------------------------------------
  */

  function resetDp() {

    const nominal =
      element(
        "#paymentDpNominalInput"
      );

    const percent =
      element(
        "#paymentDpPercentInput"
      );

    const method =
      element(
        "#paymentDpMethod"
      );

    const nominalMode =
      element(
        "#paymentDpNominal"
      );


    /*
     * Reset nominal.
     */
    if (nominal) {
      nominal.value = "";
    }


    /*
     * Default persentase.
     */
    if (percent) {
      percent.value = "50";
    }


    /*
     * Default metode DP = Tunai.
     */
    if (method) {
      method.value = "tunai";
    }


    /*
     * Default input = nominal.
     */
    if (nominalMode) {
      nominalMode.checked = true;
    }


    /*
     * Reset highlight metode.
     */
    document
      .querySelectorAll(
        "[data-payment-dp-method]"
      )
      .forEach(
        (button) => {

          button.classList.toggle(
            "active",
            button.dataset
              .paymentDpMethod ===
              "tunai"
          );
        }
      );


    toggleDpInput();
    updateDpSummary();

    resetBackdateSection(
      "Dp"
    );
  }


  /*
  |--------------------------------------------------------------------------
  | TOGGLE INPUT DP NOMINAL / PERSEN
  |--------------------------------------------------------------------------
  */

  function toggleDpInput() {

    const mode =
      element(
        'input[name="paymentDpMode"]:checked'
      );

    const nominalGroup =
      element(
        "#paymentDpNominalGroup"
      );

    const percentGroup =
      element(
        "#paymentDpPercentGroup"
      );


    const isNominal =
      !mode ||
      mode.value === "nominal";


    if (nominalGroup) {

      nominalGroup.classList.toggle(
        "d-none",
        !isNominal
      );
    }


    if (percentGroup) {

      percentGroup.classList.toggle(
        "d-none",
        isNominal
      );
    }


    updateDpSummary();
  }


  /*
  |--------------------------------------------------------------------------
  | UPDATE RINGKASAN DP
  |--------------------------------------------------------------------------
  */

  function updateDpSummary() {

    const selectedMode =
      element(
        'input[name="paymentDpMode"]:checked'
      );


    const total =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    let amount = 0;


    /*
     * Nominal.
     */
    if (
      !selectedMode ||
      selectedMode.value === "nominal"
    ) {

      amount =
        numberFromInput(
          element(
            "#paymentDpNominalInput"
          )
        );

    } else {

      /*
       * Persentase.
       */
      const percent =
        Number(
          element(
            "#paymentDpPercentInput"
          )?.value
        ) || 0;


      amount =
        Math.round(
          (percent / 100) * total
        );
    }


    /*
     * Jangan lebih besar dari total/sisa.
     */
    amount =
      Math.max(
        0,
        Math.min(
          amount,
          total
        )
      );


    state.dpAmount =
      amount;


    const labels =
      state.mode === "existing"
        ? "Sisa Tagihan"
        : "Total Belanja";


    const totalLabel =
      element(
        "#paymentDpSummaryTotalLabel"
      );


    if (totalLabel) {

      totalLabel.textContent =
        labels;
    }


    const summaryTotal =
      element(
        "#paymentDpSummaryTotal"
      );

    if (summaryTotal) {

      summaryTotal.textContent =
        formatRupiah(total);
    }


    const summaryPaid =
      element(
        "#paymentDpSummaryPaid"
      );

    if (summaryPaid) {

      summaryPaid.textContent =
        formatRupiah(amount);
    }


    const summaryRemaining =
      element(
        "#paymentDpSummaryRemaining"
      );

    if (summaryRemaining) {

      summaryRemaining.textContent =
        formatRupiah(
          total - amount
        );
    }
  }


  /*
  |--------------------------------------------------------------------------
  | REQUEST JSON
  |--------------------------------------------------------------------------
  */

  function requestJson(
    url,
    payload
  ) {

    return window
      .fetch(
        url,
        {
          method: "POST",

          headers: {
            "Content-Type":
              "application/json",
          },

          body:
            JSON.stringify(
              payload
            ),
        }
      )
      .then(
        async (response) => {

          const data =
            await response
              .json()
              .catch(
                () => ({})
              );


          if (!response.ok) {

            throw new Error(
              data.message ||
              "Gagal memproses pembayaran."
            );
          }


          return data;
        }
      );
  }


  /*
  |--------------------------------------------------------------------------
  | CONFIG
  |--------------------------------------------------------------------------
  */

  function config() {

    return (
      window.paymentModalConfig ||
      {}
    );
  }


  /*
  |--------------------------------------------------------------------------
  | BACKDATE / PEMBAYARAN DITERIMA SEBELUMNYA (2026-09-05)
  |--------------------------------------------------------------------------
  |
  | Hanya untuk:
  | - admin (config().isAdmin === true)
  | - transaksi existing (state.mode === "existing"), BUKAN transaksi
  |   baru yang sedang dibuat di halaman kasir.
  |
  | Tidak mengubah flow/modal pembayaran; hanya menambah field
  | opsional di 3 modal yang sudah ada (Tunai, DP, Konfirmasi
  | QRIS/Transfer). Jika tidak dicentang, behavior identik dengan
  | sebelum fitur ini ada.
  |
  */

  function isAdminUser() {

    return (
      config().isAdmin ===
      true
    );
  }


  function backdateAllowed() {

    return (
      isAdminUser() &&
      state.mode === "existing"
    );
  }


  /*
  |--------------------------------------------------------------------------
  | AMBIL DAFTAR KASIR (lazy, sekali fetch, di-cache)
  |--------------------------------------------------------------------------
  */

  function loadKasirList() {

    if (kasirListCache) {

      return Promise.resolve(
        kasirListCache
      );
    }

    if (kasirListPromise) {

      return kasirListPromise;
    }

    const url =
      config().kasirListUrl ||
      "/api/kasir-list";

    kasirListPromise = fetch(
      url,
      {
        headers: {
          "X-Requested-With":
            "XMLHttpRequest",
        },
      }
    )
      .then((response) => response.json())
      .then((data) => {

        kasirListCache =
          data && data.status === "success" && Array.isArray(data.data)
            ? data.data
            : [];

        return kasirListCache;
      })
      .catch(() => {

        kasirListCache = [];

        return kasirListCache;
      });

    return kasirListPromise;
  }


  function populateKasirSelect(selectElement) {

    if (!selectElement) {
      return;
    }

    /*
     * Sudah pernah diisi sebelumnya, tidak perlu diulang.
     */
    if (
      selectElement.dataset
        .populated === "true"
    ) {
      return;
    }

    loadKasirList().then((list) => {

      list.forEach((user) => {

        const option =
          document.createElement(
            "option"
          );

        option.value =
          user.id;

        option.textContent =
          user.nama ||
          user.username ||
          ("User #" + user.id);

        selectElement.appendChild(
          option
        );
      });

      selectElement.dataset.populated =
        "true";
    });
  }


  /*
  |--------------------------------------------------------------------------
  | TOGGLE VISIBILITY SECTION BACKDATE PER MODAL
  |--------------------------------------------------------------------------
  |
  | scope: "Cash" | "Dp" | "Confirm"
  |
  */

  function resetBackdateSection(scope) {

    const section =
      element(
        "#paymentBackdateSection" +
          scope
      );

    const checkbox =
      element(
        "#paymentBackdateCheck" +
          scope
      );

    const fields =
      element(
        "#paymentBackdateFields" +
          scope
      );

    const tanggalInput =
      element(
        "#paymentBackdateTanggal" +
          scope
      );

    const kasirSelect =
      element(
        "#paymentBackdateKasir" +
          scope
      );


    if (!section) {
      return;
    }

    /*
     * Tampilkan section hanya untuk admin & transaksi existing.
     */
    section.classList.toggle(
      "d-none",
      !backdateAllowed()
    );


    /*
     * Selalu reset ke kondisi tidak dicentang saat modal dibuka
     * ulang — mencegah backdate "nempel" dari transaksi sebelumnya.
     */
    if (checkbox) {
      checkbox.checked = false;
    }

    if (fields) {
      fields.classList.add(
        "d-none"
      );
    }

    if (tanggalInput) {
      tanggalInput.value = "";
    }

    if (kasirSelect) {
      kasirSelect.value = "";
    }


    if (backdateAllowed()) {
      populateKasirSelect(
        kasirSelect
      );
    }
  }


  /*
  |--------------------------------------------------------------------------
  | BACA EXTRAS BACKDATE UNTUK PAYLOAD SUBMIT
  |--------------------------------------------------------------------------
  |
  | Mengembalikan {} (kosong) jika checkbox tidak dicentang, sehingga
  | behavior default (tanggal = sekarang, kasir_id = kasir login) di
  | backend tidak berubah sama sekali.
  |
  */

  function backdateExtras(scope) {

    if (!backdateAllowed()) {
      return {};
    }

    const checkbox =
      element(
        "#paymentBackdateCheck" +
          scope
      );

    if (
      !checkbox ||
      !checkbox.checked
    ) {
      return {};
    }

    const tanggalInput =
      element(
        "#paymentBackdateTanggal" +
          scope
      );

    const kasirSelect =
      element(
        "#paymentBackdateKasir" +
          scope
      );

    const extras = {};


    /*
     * <input type="datetime-local"> mengembalikan "YYYY-MM-DDTHH:MM".
     * Backend menerima string tanggal apa pun yang bisa dibaca
     * strtotime(); ganti "T" jadi spasi + tambah detik agar format
     * konsisten dengan kolom datetime di database.
     */
    if (
      tanggalInput &&
      tanggalInput.value
    ) {

      extras.tanggal =
        tanggalInput.value.replace(
          "T",
          " "
        ) + ":00";
    }

    if (
      kasirSelect &&
      kasirSelect.value
    ) {

      extras.kasir_id =
        kasirSelect.value;
    }

    return extras;
  }


  /*
  |--------------------------------------------------------------------------
  | EXISTING PAYMENT URL
  |--------------------------------------------------------------------------
  */

  function existingUrl() {

    const settings =
      config();


    /*
     * Pelunasan melalui halaman tagihan.
     */
    if (
      state.existingFlow ===
      "tagihan-lunasi"
    ) {

      if (
        !settings.tagihanLunasiUrl
      ) {

        return null;
      }


      return settings
        .tagihanLunasiUrl
        .replace(
          ":id",
          state.transaksiId
        );
    }


    /*
     * Pembayaran biasa transaksi existing.
     */
    return (
      settings.existingPaymentUrl ||
      null
    );
  }


  /*
  |--------------------------------------------------------------------------
  | PAYLOAD KASIR
  |--------------------------------------------------------------------------
  */

  function buildKasirPayload(
    metode,
    extras
  ) {

    if (
      typeof state.kasirPayload ===
      "function"
    ) {

      return state.kasirPayload(
        metode,
        extras
      );
    }


    return {
      keranjang:
        state.cart,

      metode:
        metode,

      ...extras,
    };
  }


  /*
  |--------------------------------------------------------------------------
  | PAYLOAD TRANSAKSI EXISTING
  |--------------------------------------------------------------------------
  */

  function existingPayload(
    metode,
    extras
  ) {

    /*
     * Khusus tagihan/lunasi.
     */
    if (
      state.existingFlow ===
      "tagihan-lunasi"
    ) {

      return {
        metode:
          metode,

        ...extras,
      };
    }


    return {

      transaksi_id:
        state.transaksiId,

      jumlah:
        extras.jumlah,

      metode:
        metode,

      ...extras,
    };
  }


  /*
  |--------------------------------------------------------------------------
  | SUBMIT PAYMENT
  |--------------------------------------------------------------------------
  */

  function submitPayment(
    metode,
    extras
  ) {

    /*
     * Cegah double submit.
     */
    if (state.submitting) {
      return;
    }


    const url =
      state.mode === "kasir"
        ? config().kasirTransactionUrl
        : existingUrl();


    if (!url) {

      notify(
        "Konfigurasi endpoint pembayaran belum tersedia.",
        "danger"
      );

      return;
    }


    state.submitting =
      true;


    notify(
      "Memproses pembayaran...",
      "info"
    );


    const payload =
      state.mode === "kasir"
        ? buildKasirPayload(
            metode,
            extras
          )
        : existingPayload(
            metode,
            extras
          );


    requestJson(
      url,
      payload
    )
      .then(
        (response) => {

          /*
           * Pastikan response aplikasi sukses.
           */
          if (
            response.status !==
            "success"
          ) {

            throw new Error(
              response.message ||
              "Pembayaran gagal diproses."
            );
          }


          /*
           * Callback dari halaman.
           */
          if (
            typeof state.onSuccess ===
            "function"
          ) {

            state.onSuccess(
              response,
              {
                metode:
                  metode,

                ...extras,
              }
            );

          } else {

            notify(
              response.message ||
              "Pembayaran berhasil diproses.",
              "success"
            );
          }
        }
      )
      .catch(
        (error) => {

          notify(
            error.message ||
            "Gagal memproses pembayaran.",
            "danger"
          );
        }
      )
      .finally(
        () => {

          state.submitting =
            false;
        }
      );
  }


  /*
  |--------------------------------------------------------------------------
  | PILIH METODE PEMBAYARAN UTAMA
  |--------------------------------------------------------------------------
  */

  function chooseMethod(metode) {

    /*
    |--------------------------------------------------------------------------
    | TUNAI
    |--------------------------------------------------------------------------
    |
    | Tunai membuka input uang terlebih dahulu.
    |
    */

    if (metode === "tunai") {

      resetCash();

      hideModal(
        selectors.main
      );

      showModal(
        selectors.cash
      );


      window.setTimeout(
        () =>
          element(
            "#paymentCashReceived"
          )?.focus(),
        300
      );


      return;
    }


    /*
    |--------------------------------------------------------------------------
    | HITUNG JUMLAH
    |--------------------------------------------------------------------------
    */

    let amount =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    if (
      state.mode === "existing" &&
      state.allowPartialNonCash
    ) {

      amount =
        state.sisa;
    }


    /*
    |--------------------------------------------------------------------------
    | QRIS / TRANSFER
    |--------------------------------------------------------------------------
    |
    | Jangan langsung submit.
    | Masuk ke modal konfirmasi.
    |
    */

    if (
      metode === "qris" ||
      metode === "transfer"
    ) {

      showPaymentConfirmation(
        metode,
        amount,
        {
          type: "normal",
          jumlah: amount,
        }
      );


      return;
    }


    /*
    |--------------------------------------------------------------------------
    | METODE LAIN
    |--------------------------------------------------------------------------
    */

    submitPayment(
      metode,
      {
        jumlah:
          amount,
      }
    );


    hideModal(
      selectors.main
    );
  }


  /*
  |--------------------------------------------------------------------------
  | TAMPILKAN KONFIRMASI NON-TUNAI
  |--------------------------------------------------------------------------
  |
  | Dipakai baik untuk pembayaran normal maupun DP.
  |
  */

  function showPaymentConfirmation(
    metode,
    amount,
    extras
  ) {

    const methodNames = {
      qris:
        "QRIS",

      transfer:
        "TRANSFER",
    };


    const methodLabel =
      methodNames[metode] ||
      String(
        metode
      ).toUpperCase();


    /*
     * Simpan state konfirmasi.
     */
    state.confirmMetode =
      metode;

    state.confirmAmount =
      amount;

    state.confirmExtras =
      extras || {};


    /*
     * Isi nama metode.
     */
    const methodElement =
      element(
        "#paymentConfirmMethod"
      );

    if (methodElement) {

      methodElement.textContent =
        methodLabel;
    }


    /*
     * Isi nominal.
     */
    const amountElement =
      element(
        "#paymentConfirmAmount"
      );

    if (amountElement) {

      amountElement.textContent =
        formatRupiah(
          amount
        );
    }


    /*
     * Tampilkan jenis pembayaran.
     */
    const detailElement =
      element(
        "#paymentConfirmDetail"
      );

    if (detailElement) {

      detailElement.textContent =
        extras?.type === "dp"
          ? "Pembayaran DP"
          : "Pembayaran transaksi";
    }


    /*
     * Tutup modal yang sedang terbuka.
     */
    hideModal(
      selectors.main
    );

    hideModal(
      selectors.dp
    );


    /*
     * Buka konfirmasi.
     */
    resetBackdateSection(
      "Confirm"
    );

    showModal(
      selectors.confirm
    );
  }


  /*
  |--------------------------------------------------------------------------
  | KONFIRMASI PEMBAYARAN
  |--------------------------------------------------------------------------
  |
  | Dipanggil ketika user klik:
  | "Sudah Dibayar & Simpan"
  |
  */

  function confirmPayment() {

    if (!state.confirmMetode) {
      return;
    }


    const metode =
      state.confirmMetode;

    const extras =
      state.confirmExtras ||
      {};


    /*
    |--------------------------------------------------------------------------
    | Simpan data sebelum state dibersihkan.
    |--------------------------------------------------------------------------
    */

    const confirmAmount =
      state.confirmAmount;


    const dpAmount =
      state.dpAmount;


    /*
    |--------------------------------------------------------------------------
    | Tangkap backdate SEBELUM modal ditutup / state dibersihkan.
    |--------------------------------------------------------------------------
    */

    const backdate =
      backdateExtras(
        "Confirm"
      );


    /*
    |--------------------------------------------------------------------------
    | Bersihkan state konfirmasi.
    |--------------------------------------------------------------------------
    */

    state.confirmMetode =
      null;

    state.confirmAmount =
      0;

    state.confirmExtras =
      null;


    /*
    |--------------------------------------------------------------------------
    | Tutup modal konfirmasi.
    |--------------------------------------------------------------------------
    */

    hideModal(
      selectors.confirm
    );


    /*
    |--------------------------------------------------------------------------
    | PEMBAYARAN DP
    |--------------------------------------------------------------------------
    */

    if (
      extras.type === "dp"
    ) {

      /*
       * DP dari halaman kasir baru.
       */
      if (
        state.mode === "kasir"
      ) {

        submitPayment(
          "dp",
          {
            jumlah_dp:
              dpAmount,

            metode_dp:
              metode,
          }
        );


        return;
      }


      /*
       * DP pada transaksi existing.
       */
      submitPayment(
        metode,
        {
          jumlah:
            dpAmount,

          keterangan:
            "DP (Rp " +
            dpAmount.toLocaleString(
              "id-ID"
            ) +
            ")",

          ...backdate,
        }
      );


      return;
    }


    /*
    |--------------------------------------------------------------------------
    | PEMBAYARAN NORMAL
    |--------------------------------------------------------------------------
    */

    submitPayment(
      metode,
      {
        jumlah:
          extras.jumlah ??
          confirmAmount,

        ...backdate,
      }
    );
  }


  /*
  |--------------------------------------------------------------------------
  | BUKA MODAL DP
  |--------------------------------------------------------------------------
  */

  function openDp() {

    resetDp();

    hideModal(
      selectors.main
    );

    showModal(
      selectors.dp
    );
  }


  /*
  |--------------------------------------------------------------------------
  | SUBMIT TUNAI
  |--------------------------------------------------------------------------
  */

  function submitCash() {

    const due =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    const received =
      numberFromInput(
        element(
          "#paymentCashReceived"
        )
      );


    if (received < due) {

      notify(
        "Uang yang diterima kurang dari total belanja.",
        "danger"
      );

      return;
    }


    hideModal(
      selectors.cash
    );


    submitPayment(
      "tunai",
      {
        jumlah:
          due,

        uang_diterima:
          received,

        kembalian:
          received - due,

        ...backdateExtras(
          "Cash"
        ),
      }
    );
  }


  /*
  |--------------------------------------------------------------------------
  | SUBMIT DP
  |--------------------------------------------------------------------------
  */

  function submitDp() {

    /*
    |--------------------------------------------------------------------------
    | VALIDASI NOMINAL DP
    |--------------------------------------------------------------------------
    */

    if (state.dpAmount <= 0) {

      notify(
        "Jumlah DP harus lebih dari 0.",
        "warning"
      );

      return;
    }


    /*
    |--------------------------------------------------------------------------
    | AMBIL METODE DP
    |--------------------------------------------------------------------------
    */

    const metode =
      element(
        "#paymentDpMethod"
      )?.value ||
      "tunai";


    /*
    |--------------------------------------------------------------------------
    | QRIS / TRANSFER
    |--------------------------------------------------------------------------
    |
    | Jangan langsung simpan.
    | Masuk ke konfirmasi.
    |
    */

    if (
      metode === "qris" ||
      metode === "transfer"
    ) {

      showPaymentConfirmation(
        metode,
        state.dpAmount,
        {
          type: "dp",
          metode:
            metode,
        }
      );


      return;
    }


    /*
    |--------------------------------------------------------------------------
    | TUNAI
    |--------------------------------------------------------------------------
    */

    hideModal(
      selectors.dp
    );


    /*
    |--------------------------------------------------------------------------
    | DP DARI KASIR BARU
    |--------------------------------------------------------------------------
    */

    if (
      state.mode === "kasir"
    ) {

      submitPayment(
        "dp",
        {
          jumlah_dp:
            state.dpAmount,

          metode_dp:
            metode,
        }
      );


      return;
    }


    /*
    |--------------------------------------------------------------------------
    | DP TRANSAKSI EXISTING
    |--------------------------------------------------------------------------
    */

    submitPayment(
      metode,
      {
        jumlah:
          state.dpAmount,

        keterangan:
          "DP (Rp " +
          state.dpAmount.toLocaleString(
            "id-ID"
          ) +
          ")",

        ...backdateExtras(
          "Dp"
        ),
      }
    );
  }


  /*
  |--------------------------------------------------------------------------
  | BIND EVENTS
  |--------------------------------------------------------------------------
  */

  function bindEvents() {

    /*
    |--------------------------------------------------------------------------
    | Hindari binding dua kali.
    |--------------------------------------------------------------------------
    */

    if (
      !modalIsAvailable() ||
      element(
        "#paymentModalRoot"
      )?.dataset.paymentBound ===
        "true"
    ) {

      return;
    }


    element(
      "#paymentModalRoot"
    ).dataset.paymentBound =
      "true";


    /*
    |--------------------------------------------------------------------------
    | CLICK EVENT
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
      "click",
      (event) => {

        /*
        |--------------------------------------------------------------------------
        | METODE PEMBAYARAN UTAMA
        |--------------------------------------------------------------------------
        */

        const methodButton =
          event.target.closest(
            "[data-payment-method]"
          );


        if (methodButton) {

          chooseMethod(
            methodButton.dataset
              .paymentMethod
          );

          return;
        }


        /*
        |--------------------------------------------------------------------------
        | METODE PEMBAYARAN DP
        |--------------------------------------------------------------------------
        |
        | PENTING:
        | Handler ini harus diletakkan SEBELUM
        | pengecekan data-payment-action.
        |
        */

        const dpMethodButton =
          event.target.closest(
            "[data-payment-dp-method]"
          );


        if (dpMethodButton) {

          const metode =
            dpMethodButton.dataset
              .paymentDpMethod;


          const input =
            element(
              "#paymentDpMethod"
            );


          /*
           * Simpan metode.
           */
          if (input) {

            input.value =
              metode;
          }


          /*
           * Highlight hanya tombol yang dipilih.
           */
          document
            .querySelectorAll(
              "[data-payment-dp-method]"
            )
            .forEach(
              (button) => {

                button.classList.toggle(
                  "active",
                  button ===
                    dpMethodButton
                );
              }
            );


          return;
        }


        /*
        |--------------------------------------------------------------------------
        | KONFIRMASI PEMBAYARAN
        |--------------------------------------------------------------------------
        */

        const confirmButton =
          event.target.closest(
            "[data-payment-confirm]"
          );


        if (confirmButton) {

          if (
            confirmButton.dataset
              .paymentConfirm ===
            "yes"
          ) {

            confirmPayment();
          }


          return;
        }


        /*
        |--------------------------------------------------------------------------
        | PECAHAN UANG
        |--------------------------------------------------------------------------
        */

        const cashButton =
          event.target.closest(
            "[data-payment-cash]"
          );


        if (cashButton) {

          addCash(
            Number(
              cashButton.dataset
                .paymentCash
            )
          );


          return;
        }


        /*
        |--------------------------------------------------------------------------
        | ACTION BUTTON
        |--------------------------------------------------------------------------
        */

        const actionButton =
          event.target.closest(
            "[data-payment-action]"
          );


        if (!actionButton) {
          return;
        }


        const action =
          actionButton.dataset
            .paymentAction;


        /*
         * Buka DP.
         */
        if (
          action === "open-dp"
        ) {

          openDp();
        }


        /*
         * Reset tunai.
         */
        if (
          action === "reset-cash"
        ) {

          resetCash();
        }


        /*
         * Uang pas.
         */
        if (
          action === "exact-cash"
        ) {

          setExactCash();
        }


        /*
         * Submit tunai.
         */
        if (
          action === "submit-cash"
        ) {

          submitCash();
        }


        /*
         * Submit DP.
         */
        if (
          action === "submit-dp"
        ) {

          submitDp();
        }
      }
    );


    /*
    |--------------------------------------------------------------------------
    | INPUT EVENT
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
      "input",
      (event) => {

        /*
         * Input uang tunai.
         */
        if (
          event.target.matches(
            "#paymentCashReceived"
          )
        ) {

          formatCashInput(
            event.target
          );
        }


        /*
         * Input nominal DP.
         */
        if (
          event.target.matches(
            "#paymentDpNominalInput"
          )
        ) {

          formatDpNominalInput(
            event.target
          );
        }


        /*
         * Input persentase DP.
         */
        if (
          event.target.matches(
            "#paymentDpPercentInput"
          )
        ) {

          updateDpSummary();
        }
      }
    );


    /*
    |--------------------------------------------------------------------------
    | CHANGE EVENT
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
      "change",
      (event) => {

        /*
         * Mode nominal / persentase DP.
         */
        if (
          event.target.matches(
            'input[name="paymentDpMode"]'
          )
        ) {

          toggleDpInput();
        }


        /*
         * Checkbox "Pembayaran diterima sebelumnya" —
         * tampil/sembunyikan field tanggal & kasir penerima.
         * Berlaku untuk ketiga modal (Cash/Dp/Confirm).
         */
        const backdateCheckbox =
          event.target.closest(
            '[id^="paymentBackdateCheck"]'
          );

        if (backdateCheckbox) {

          const scope =
            backdateCheckbox.id.replace(
              "paymentBackdateCheck",
              ""
            );

          const fields =
            element(
              "#paymentBackdateFields" +
                scope
            );

          if (fields) {

            fields.classList.toggle(
              "d-none",
              !backdateCheckbox.checked
            );
          }

          /*
           * Default tanggal ke waktu sekarang saat pertama
           * dicentang, supaya admin tinggal mengubah jika perlu
           * (bukan mulai dari field kosong).
           */
          if (backdateCheckbox.checked) {

            const tanggalInput =
              element(
                "#paymentBackdateTanggal" +
                  scope
              );

            if (
              tanggalInput &&
              !tanggalInput.value
            ) {

              const now =
                new Date();

              const pad =
                (n) =>
                  String(n).padStart(
                    2,
                    "0"
                  );

              tanggalInput.value =
                now.getFullYear() +
                "-" +
                pad(now.getMonth() + 1) +
                "-" +
                pad(now.getDate()) +
                "T" +
                pad(now.getHours()) +
                ":" +
                pad(now.getMinutes());
            }
          }
        }
      }
    );


    /*
    |--------------------------------------------------------------------------
    | ENTER PADA INPUT TUNAI
    |--------------------------------------------------------------------------
    */

    document.addEventListener(
      "keydown",
      (event) => {

        if (
          event.key === "Enter" &&
          event.target.matches(
            "#paymentCashReceived"
          )
        ) {

          event.preventDefault();

          submitCash();
        }
      }
    );
  }


  /*
  |--------------------------------------------------------------------------
  | OPEN PAYMENT MODAL
  |--------------------------------------------------------------------------
  */

  function openPaymentModal(
    options
  ) {

    /*
    |--------------------------------------------------------------------------
    | VALIDASI MODE
    |--------------------------------------------------------------------------
    */

    if (
      !options ||
      ![
        "kasir",
        "existing",
      ].includes(
        options.mode
      )
    ) {

      throw new Error(
        'Mode payment harus "kasir" atau "existing".'
      );
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSAKSI EXISTING WAJIB PUNYA ID
    |--------------------------------------------------------------------------
    */

    if (
      options.mode === "existing" &&
      !options.transaksiId
    ) {

      throw new Error(
        "transaksiId wajib tersedia untuk mode existing."
      );
    }


    /*
    |--------------------------------------------------------------------------
    | SIMPAN STATE
    |--------------------------------------------------------------------------
    */

    state.mode =
      options.mode;


    state.transaksiId =
      options.transaksiId ||
      null;


    state.total =
      Number(
        options.total
      ) || 0;


    state.sisa =
      Number(
        options.sisa ??
        options.total
      ) || 0;


    state.cart =
      options.cart ||
      [];


    state.kasirPayload =
      options.kasirPayload ||
      null;


    state.existingFlow =
      options.existingFlow ||
      "payment";


    state.allowPartialNonCash =
      options.allowPartialNonCash ===
      true;


    state.onSuccess =
      options.onSuccess ||
      null;


    state.dpAmount =
      0;


    /*
    |--------------------------------------------------------------------------
    | RESET STATE KONFIRMASI
    |--------------------------------------------------------------------------
    */

    state.confirmMetode =
      null;

    state.confirmAmount =
      0;

    state.confirmExtras =
      null;


    /*
    |--------------------------------------------------------------------------
    | TENTUKAN TOTAL
    |--------------------------------------------------------------------------
    */

    const amount =
      state.mode === "existing"
        ? state.sisa
        : state.total;


    const totalLabel =
      state.mode === "existing"
        ? "Sisa Tagihan"
        : "Total Belanja";


    /*
    |--------------------------------------------------------------------------
    | BOLEH DP?
    |--------------------------------------------------------------------------
    */

    const dpAllowed =
      options.allowDp !== false &&
      state.existingFlow !==
        "tagihan-lunasi";


    /*
    |--------------------------------------------------------------------------
    | UPDATE MODAL UTAMA
    |--------------------------------------------------------------------------
    */

    const paymentTotal =
      element(
        "#paymentTotal"
      );


    if (paymentTotal) {

      paymentTotal.textContent =
        formatRupiah(amount);
    }


    const paymentTotalLabel =
      element(
        "#paymentTotalLabel"
      );


    if (paymentTotalLabel) {

      paymentTotalLabel.textContent =
        totalLabel;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE MODAL TUNAI
    |--------------------------------------------------------------------------
    */

    const cashTotal =
      element(
        "#paymentCashTotal"
      );


    if (cashTotal) {

      cashTotal.textContent =
        formatRupiah(amount);
    }


    const cashTotalLabel =
      element(
        "#paymentCashTotalLabel"
      );


    if (cashTotalLabel) {

      cashTotalLabel.textContent =
        totalLabel;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE MODAL DP
    |--------------------------------------------------------------------------
    */

    const dpTotal =
      element(
        "#paymentDpTotal"
      );


    if (dpTotal) {

      dpTotal.textContent =
        formatRupiah(amount);
    }


    const dpTotalLabel =
      element(
        "#paymentDpTotalLabel"
      );


    if (dpTotalLabel) {

      dpTotalLabel.textContent =
        totalLabel.toLowerCase();
    }


    /*
    |--------------------------------------------------------------------------
    | TAMPILKAN / SEMBUNYIKAN TOMBOL DP
    |--------------------------------------------------------------------------
    */

    const dpDivider =
      element(
        "[data-payment-dp-divider]"
      );


    if (dpDivider) {

      dpDivider.style.display =
        dpAllowed
          ? ""
          : "none";
    }


    const dpButton =
      element(
        '[data-payment-action="open-dp"]'
      );


    if (dpButton) {

      dpButton.style.display =
        dpAllowed
          ? ""
          : "none";
    }


    /*
    |--------------------------------------------------------------------------
    | PASTIKAN EVENTS TERPASANG
    |--------------------------------------------------------------------------
    */

    bindEvents();


    /*
    |--------------------------------------------------------------------------
    | TAMPILKAN MODAL UTAMA
    |--------------------------------------------------------------------------
    */

    showModal(
      selectors.main
    );
  }


  /*
  |--------------------------------------------------------------------------
  | PUBLIC API
  |--------------------------------------------------------------------------
  */

  window.bukaPaymentModal =
    function (options) {

      return ensureModalLoaded()
        .then(
          () =>
            openPaymentModal(
              options
            )
        );
    };


})(window, document);