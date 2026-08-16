    <!-- Full Screenshot Modal -->
    <div class="modal fade modal-modern" id="screenshotModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fa fa-image"></i> إيصال الدفع - الطلب #{{ $order->id }}
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">
                        <span>&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <img id="fullScreenshotImage" src="" alt="إيصال الدفع">
                </div>
                <div class="modal-footer">
                    <a id="downloadFullScreenshot" href="" download class="btn btn-primary">
                        <i class="fa fa-download"></i> تحميل الصورة
                    </a>
                </div>
            </div>
        </div>
    </div>
