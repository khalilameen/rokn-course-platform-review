package com.rokn.checkout

import android.app.Activity
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.view.Gravity
import android.view.View
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.CookieManager
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.appcompat.app.AppCompatActivity
import androidx.core.net.toUri
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import com.rokn.BuildConfig
import java.text.NumberFormat
import java.util.Locale

class CheckoutActivity : AppCompatActivity() {
  private lateinit var webView: WebView
  private lateinit var progress: ProgressBar
  private lateinit var errorMessage: TextView

  override fun onCreate(savedInstanceState: Bundle?) {
    super.onCreate(savedInstanceState)
    WindowCompat.setDecorFitsSystemWindows(window, false)
    WindowInsetsControllerCompat(window, window.decorView).apply {
      isAppearanceLightStatusBars = false
      isAppearanceLightNavigationBars = false
    }
    window.statusBarColor = BACKGROUND
    window.navigationBarColor = BACKGROUND
    setContentView(buildContent())
    onBackPressedDispatcher.addCallback(
      this,
      object : OnBackPressedCallback(true) {
        override fun handleOnBackPressed() {
          if (::webView.isInitialized && webView.canGoBack()) {
            webView.goBack()
          } else {
            finishCancelled()
          }
        }
      },
    )

    val url = intent.getStringExtra(EXTRA_URL).orEmpty()
    if (url.startsWith(DEMO_SCHEME)) {
      loadDemoCheckout(url.toUri())
    } else if (isTrustedCheckoutEntry(url)) {
      webView.loadUrl(url)
    } else {
      showError("تعذر فتح صفحة الدفع الآمنة")
    }
  }

  private fun buildContent(): View {
    val root = LinearLayout(this).apply {
      orientation = LinearLayout.VERTICAL
      setBackgroundColor(BACKGROUND)
    }
    ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
      val safeInsets = insets.getInsets(
        WindowInsetsCompat.Type.systemBars() or
          WindowInsetsCompat.Type.displayCutout() or
          WindowInsetsCompat.Type.ime(),
      )
      view.setPadding(
        safeInsets.left,
        safeInsets.top,
        safeInsets.right,
        safeInsets.bottom,
      )
      insets
    }

    val toolbar = FrameLayout(this).apply {
      setPadding(dp(12), 0, dp(12), 0)
      setBackgroundColor(BACKGROUND)
    }
    toolbar.addView(
      TextView(this).apply {
        text = "دفع آمن"
        textSize = 17f
        setTextColor(Color.WHITE)
        gravity = Gravity.CENTER
      },
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        dp(56),
      ),
    )
    toolbar.addView(
      TextView(this).apply {
        text = "×"
        textSize = 34f
        setTextColor(Color.WHITE)
        gravity = Gravity.CENTER
        contentDescription = "إغلاق"
        setOnClickListener { finishCancelled() }
      },
      FrameLayout.LayoutParams(dp(48), dp(56), Gravity.START),
    )
    root.addView(
      toolbar,
      LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT,
        dp(56),
      ),
    )

    val content = FrameLayout(this)
    webView = WebView(this).apply {
      setBackgroundColor(BACKGROUND)
      settings.javaScriptEnabled = true
      settings.domStorageEnabled = true
      settings.cacheMode = WebSettings.LOAD_NO_CACHE
      settings.saveFormData = false
      settings.allowFileAccess = false
      settings.allowContentAccess = false
      settings.allowFileAccessFromFileURLs = false
      settings.allowUniversalAccessFromFileURLs = false
      settings.javaScriptCanOpenWindowsAutomatically = false
      settings.setSupportMultipleWindows(false)
      settings.mixedContentMode = WebSettings.MIXED_CONTENT_NEVER_ALLOW
      if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
        settings.safeBrowsingEnabled = true
      }
      webViewClient = object : WebViewClient() {
        override fun shouldOverrideUrlLoading(
          view: WebView?,
          request: WebResourceRequest?,
        ): Boolean = handleNavigation(request?.url)

        @Suppress("DEPRECATION")
        override fun shouldOverrideUrlLoading(view: WebView?, url: String?): Boolean =
          handleNavigation(url?.toUri())

        override fun onPageFinished(view: WebView?, url: String?) {
          this@CheckoutActivity.progress.visibility = View.GONE
          errorMessage.visibility = View.GONE
        }

        override fun onReceivedError(
          view: WebView?,
          request: WebResourceRequest?,
          error: WebResourceError?,
        ) {
          if (request?.isForMainFrame == true) {
            showError("الاتصال بصفحة الدفع غير متاح الآن. حاول مرة أخرى.")
          }
        }
      }
    }
    progress = ProgressBar(this).apply {
      isIndeterminate = true
    }
    errorMessage = TextView(this).apply {
      visibility = View.GONE
      gravity = Gravity.CENTER
      textSize = 16f
      setTextColor(Color.WHITE)
      setPadding(dp(28), dp(28), dp(28), dp(28))
    }
    content.addView(
      webView,
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        FrameLayout.LayoutParams.MATCH_PARENT,
      ),
    )
    content.addView(
      progress,
      FrameLayout.LayoutParams(dp(44), dp(44), Gravity.CENTER),
    )
    content.addView(
      errorMessage,
      FrameLayout.LayoutParams(
        FrameLayout.LayoutParams.MATCH_PARENT,
        FrameLayout.LayoutParams.MATCH_PARENT,
      ),
    )
    root.addView(
      content,
      LinearLayout.LayoutParams(
        LinearLayout.LayoutParams.MATCH_PARENT,
        0,
        1f,
      ),
    )
    return root
  }

  private fun handleNavigation(uri: Uri?): Boolean {
    if (uri == null) return false
    if (uri.scheme.equals("rokn", ignoreCase = true) &&
      (uri.host == "payment-result" || uri.host == "checkout")
    ) {
      setResult(
        Activity.RESULT_OK,
        Intent().putExtra(RESULT_URL, uri.toString()),
      )
      finish()
      return true
    }
    return !isAllowedWebNavigation(uri)
  }

  private fun loadDemoCheckout(uri: Uri) {
    val coins = uri.getQueryParameter("coins")?.toIntOrNull()?.coerceAtLeast(0) ?: 0
    val price = uri.getQueryParameter("price")?.toDoubleOrNull()?.coerceAtLeast(0.0) ?: 0.0
    val orderRef = uri.getQueryParameter("order_ref") ?: "DEMO-${System.currentTimeMillis()}"
    val arabicNumber = NumberFormat.getNumberInstance(Locale("ar", "EG"))
    val coinsLabel = arabicNumber.format(coins)
    val priceLabel = arabicNumber.apply {
      minimumFractionDigits = 0
      maximumFractionDigits = 2
    }.format(price)
    val html = """
      <!doctype html><html lang="ar" dir="rtl"><head>
      <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"/>
      <style>
      *{box-sizing:border-box}body{margin:0;background:#080b12;color:#f7f8fb;font-family:Arial,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:24px}
      .card{width:min(100%,460px);background:#111722;border:1px solid #242c39;border-radius:24px;padding:28px;box-shadow:0 24px 70px #0009}.secure{color:#9ca8ba;font-size:13px}.coin{display:flex;align-items:center;justify-content:center;gap:10px;color:#e4b64d;font-size:44px;font-weight:800;margin:18px 0 3px}.mark{display:inline-grid;place-items:center;width:38px;height:38px;border-radius:50%;border:2px solid #f1cb70;background:linear-gradient(145deg,#f6d77e,#a66d16);color:#19130a;font-size:21px;font-weight:900;box-shadow:inset 0 2px 3px #fff7,0 5px 18px #d8a63c33}.price{font-size:18px;color:#d8dde7;margin-bottom:28px}.line{height:1px;background:#252c38;margin:22px 0}.label{font-size:14px;color:#9ca8ba;margin-bottom:8px}.fake{height:52px;border-radius:14px;border:1px solid #323b4b;background:#0b0f17;margin-bottom:12px}.button{display:block;width:100%;border:0;border-radius:16px;padding:17px;background:#2457f5;color:white;font-size:17px;font-weight:700;text-decoration:none;text-align:center}.note{font-size:12px;line-height:1.8;color:#7e899b;margin-top:16px;text-align:center}
      </style></head><body><main class="card"><div class="secure">شحن رصيد ركن</div><div class="coin"><span class="mark">R</span><span>${coinsLabel}</span></div><div class="price">${priceLabel} جنيه</div><div class="line"></div><div class="label">بيانات الدفع</div><div class="fake"></div><div class="fake"></div><a class="button" href="rokn://payment-result?status=success&amp;order_ref=${orderRef}&amp;coins=${coins}">تأكيد الشحن</a><div class="note">لن يُخصم أي مبلغ في هذه النسخة التجريبية</div></main></body></html>
    """.trimIndent()
    webView.loadDataWithBaseURL("https://checkout.rokn.app", html, "text/html", "UTF-8", null)
  }

  private fun isTrustedCheckoutEntry(url: String): Boolean {
    val uri = runCatching { url.toUri() }.getOrNull() ?: return false
    val trustedKashier =
      uri.scheme.equals("https", ignoreCase = true) &&
        uri.host.equals("checkout.kashier.io", ignoreCase = true)
    val localDebug =
      BuildConfig.DEBUG &&
        uri.scheme.equals("http", ignoreCase = true) &&
        (uri.host == "10.0.2.2" || uri.host == "127.0.0.1" || uri.host == "localhost")
    return trustedKashier || localDebug
  }

  /**
   * Kashier may hand the main frame to a bank-hosted 3-D Secure page. Keep
   * that HTTPS navigation inside the isolated checkout activity, while the
   * entry URL itself remains pinned to Kashier above.
   */
  private fun isAllowedWebNavigation(uri: Uri): Boolean {
    return uri.scheme.equals("https", ignoreCase = true) ||
      (BuildConfig.DEBUG && uri.scheme.equals("http", ignoreCase = true))
  }

  private fun showError(message: String) {
    progress.visibility = View.GONE
    webView.visibility = View.GONE
    errorMessage.text = message
    errorMessage.visibility = View.VISIBLE
  }

  override fun onDestroy() {
    if (::webView.isInitialized) {
      webView.stopLoading()
      webView.clearHistory()
      webView.clearCache(true)
      webView.removeAllViews()
      webView.destroy()
    }
    // Payment providers and 3-D Secure pages may create temporary session
    // cookies. Remove only session cookies: persistent app/browser cookies
    // remain untouched.
    CookieManager.getInstance().removeSessionCookies(null)
    CookieManager.getInstance().flush()
    super.onDestroy()
  }

  private fun finishCancelled() {
    setResult(Activity.RESULT_CANCELED)
    finish()
  }

  private fun dp(value: Int): Int = (value * resources.displayMetrics.density).toInt()

  companion object {
    const val EXTRA_URL = "checkout_url"
    const val RESULT_URL = "result_url"
    private const val DEMO_SCHEME = "rokn-demo://checkout"
    private val BACKGROUND = Color.rgb(8, 11, 18)
  }
}
