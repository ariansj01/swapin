package ir.swaapin.mobile

import android.Manifest
import android.annotation.SuppressLint
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Bitmap
import android.net.Uri
import android.os.Bundle
import android.os.Environment
import android.provider.MediaStore
import android.webkit.*
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import java.io.File
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private var filePathCallback: ValueCallback<Array<Uri>>? = null
    private var cameraImageUri: Uri? = null
    private val url = "https://swaapin.ir"

    private val fileChooserLauncher = registerForActivityResult(
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            val results = if (result.data?.data != null) {
                arrayOf(result.data!!.data!!)
            } else if (cameraImageUri != null) {
                arrayOf(cameraImageUri!!)
            } else {
                WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data)
            }
            filePathCallback?.onReceiveValue(results)
        } else {
            filePathCallback?.onReceiveValue(null)
        }
        filePathCallback = null
        cameraImageUri = null
    }

    private val permissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val geoGranted = permissions[Manifest.permission.ACCESS_FINE_LOCATION] == true ||
                        permissions[Manifest.permission.ACCESS_COARSE_LOCATION] == true
        pendingGeolocationOrigin?.let { origin ->
            pendingGeolocationCallback?.invoke(geoGranted, false)
            pendingGeolocationOrigin = null
            pendingGeolocationCallback = null
        }
    }

    private var pendingGeolocationOrigin: String? = null
    private var pendingGeolocationCallback: ((Boolean, Boolean) -> Unit)? = null

    @SuppressLint("SetJavaScriptEnabled", "MissingInflatedId")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        webView = WebView(this)
        setContentView(webView)

        setupWebView()
        configureCookieManager()

        val initialUrl = intent?.data?.toString()
        if (savedInstanceState != null) {
            webView.restoreState(savedInstanceState)
        } else if (initialUrl != null && isValidSwaapinUrl(initialUrl)) {
            webView.loadUrl(initialUrl)
        } else {
            webView.loadUrl(url)
        }
    }

    @Suppress("DEPRECATION")
    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        val data = intent.data?.toString()
        if (data != null && isValidSwaapinUrl(data)) {
            webView.loadUrl(data)
        }
    }

    private fun isValidSwaapinUrl(target: String): Boolean {
        return try {
            val u = Uri.parse(target)
            (u.scheme == "https" || u.scheme == "http") &&
                (u.host == "swaapin.ir" || u.host?.endsWith(".swaapin.ir") == true)
        } catch (_: Exception) {
            false
        }
    }

    @SuppressLint("SetJavaScriptEnabled")
    private fun setupWebView() {
        val settings = webView.settings

        settings.javaScriptEnabled = true
        settings.domStorageEnabled = true
        settings.databaseEnabled = true
        settings.useWideViewPort = true
        settings.loadWithOverviewMode = true
        settings.setSupportZoom(true)
        settings.builtInZoomControls = true
        settings.displayZoomControls = false
        settings.allowFileAccess = true
        settings.allowContentAccess = true
        settings.mediaPlaybackRequiresUserGesture = false
        settings.mixedContentMode = WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        settings.cacheMode = WebSettings.LOAD_DEFAULT
        settings.userAgentString = settings.userAgentString.replace("; wv", "")

        webView.webViewClient = object : WebViewClient() {

            override fun shouldOverrideUrlLoading(
                view: WebView,
                request: WebResourceRequest
            ): Boolean {
                val uri = request.url
                val scheme = uri.scheme ?: return false

                return when {
                    scheme == "tel" -> {
                        launchIntent(Intent(Intent.ACTION_DIAL, uri))
                        true
                    }
                    scheme == "mailto" -> {
                        launchIntent(Intent(Intent.ACTION_SENDTO, uri))
                        true
                    }
                    scheme == "sms" -> {
                        launchIntent(Intent(Intent.ACTION_SENDTO, uri))
                        true
                    }
                    scheme == "whatsapp" -> {
                        launchIntent(Intent(Intent.ACTION_VIEW, uri))
                        true
                    }
                    scheme == "intent" -> {
                        try {
                            val intent = Intent.parseUri(uri.toString(), Intent.URI_INTENT_SCHEME)
                            launchIntent(intent)
                        } catch (_: Exception) {}
                        true
                    }
                    uri.host == "swaapin.ir" || uri.host?.endsWith(".swaapin.ir") == true -> {
                        false
                    }
                    else -> {
                        launchIntent(Intent(Intent.ACTION_VIEW, uri))
                        true
                    }
                }
            }

            override fun onPageStarted(view: WebView?, webUrl: String?, favicon: Bitmap?) {
                super.onPageStarted(view, webUrl, favicon)
            }

            override fun onPageFinished(view: WebView?, webUrl: String?) {
                super.onPageFinished(view, webUrl)
            }
        }

        webView.webChromeClient = object : WebChromeClient() {

            override fun onPermissionRequest(request: PermissionRequest) {
                val resources = request.resources
                val requestedList = mutableListOf<String>()

                if (resources.contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)) {
                    if (ContextCompat.checkSelfPermission(
                            this@MainActivity,
                            Manifest.permission.CAMERA
                        ) != PackageManager.PERMISSION_GRANTED
                    ) {
                        requestedList.add(Manifest.permission.CAMERA)
                    }
                }
                if (resources.contains(PermissionRequest.RESOURCE_AUDIO_CAPTURE)) {
                    if (ContextCompat.checkSelfPermission(
                            this@MainActivity,
                            Manifest.permission.RECORD_AUDIO
                        ) != PackageManager.PERMISSION_GRANTED
                    ) {
                        requestedList.add(Manifest.permission.RECORD_AUDIO)
                    }
                }

                if (requestedList.isEmpty()) {
                    request.grant(resources)
                } else {
                    permissionRequestRef = request
                    ActivityCompat.requestPermissions(
                        this@MainActivity,
                        requestedList.toTypedArray(),
                        REQUEST_PERMISSION_MEDIA
                    )
                }
            }

            override fun onGeolocationPermissionsShowPrompt(
                origin: String,
                callback: GeolocationPermissions.Callback
            ) {
                val fine = ContextCompat.checkSelfPermission(
                    this@MainActivity,
                    Manifest.permission.ACCESS_FINE_LOCATION
                ) == PackageManager.PERMISSION_GRANTED
                val coarse = ContextCompat.checkSelfPermission(
                    this@MainActivity,
                    Manifest.permission.ACCESS_COARSE_LOCATION
                ) == PackageManager.PERMISSION_GRANTED

                if (fine || coarse) {
                    callback.invoke(origin, true, false)
                } else {
                    pendingGeolocationOrigin = origin
                    pendingGeolocationCallback = { granted, retain ->
                        callback.invoke(origin, granted, retain)
                    }
                    permissionLauncher.launch(
                        arrayOf(
                            Manifest.permission.ACCESS_FINE_LOCATION,
                            Manifest.permission.ACCESS_COARSE_LOCATION
                        )
                    )
                }
            }

            override fun onShowFileChooser(
                webView: WebView,
                filePathCallbackParam: ValueCallback<Array<Uri>>,
                fileChooserParams: FileChooserParams
            ): Boolean {
                this@MainActivity.filePathCallback?.onReceiveValue(null)
                this@MainActivity.filePathCallback = filePathCallbackParam

                val captureMode = fileChooserParams.isCaptureEnabled
                val acceptTypes = fileChooserParams.acceptTypes ?: emptyArray()
                val isImageCapture = captureMode && acceptTypes.any {
                    it.equals("image/*", ignoreCase = true)
                }
                val isVideoCapture = captureMode && acceptTypes.any {
                    it.equals("video/*", ignoreCase = true)
                }

                val intents = mutableListOf<Intent>()

                if (isImageCapture) {
                    val takePictureIntent = Intent(MediaStore.ACTION_IMAGE_CAPTURE)
                    if (takePictureIntent.resolveActivity(packageManager) != null) {
                        var photoFile: File? = null
                        try {
                            photoFile = createImageFile()
                        } catch (_: IOException) {
                        }
                        if (photoFile != null) {
                            cameraImageUri = FileProvider.getUriForFile(
                                this@MainActivity,
                                "$packageName.fileprovider",
                                photoFile
                            )
                            takePictureIntent.putExtra(MediaStore.EXTRA_OUTPUT, cameraImageUri)
                        }
                    }
                    intents.add(takePictureIntent)
                } else if (isVideoCapture) {
                    val takeVideoIntent = Intent(MediaStore.ACTION_VIDEO_CAPTURE)
                    if (takeVideoIntent.resolveActivity(packageManager) != null) {
                        intents.add(takeVideoIntent)
                    }
                }

                val contentSelectionIntent = Intent(Intent.ACTION_GET_CONTENT).apply {
                    addCategory(Intent.CATEGORY_OPENABLE)
                    type = if (acceptTypes.size == 1) acceptTypes[0] else "*/*"
                    if (acceptTypes.size > 1) {
                        putExtra(Intent.EXTRA_MIME_TYPES, acceptTypes)
                    }
                }

                val chooserIntent = Intent.createChooser(
                    contentSelectionIntent,
                    null
                )
                if (intents.isNotEmpty()) {
                    chooserIntent.putExtra(
                        Intent.EXTRA_INITIAL_INTENTS,
                        intents.toTypedArray()
                    )
                }

                try {
                    fileChooserLauncher.launch(chooserIntent)
                } catch (_: Exception) {
                    this@MainActivity.filePathCallback = null
                    cameraImageUri = null
                    return false
                }

                return true
            }
        }
    }

    private var permissionRequestRef: PermissionRequest? = null

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        when (requestCode) {
            REQUEST_PERMISSION_MEDIA -> {
                val allGranted = grantResults.all { it == PackageManager.PERMISSION_GRANTED }
                if (allGranted) {
                    permissionRequestRef?.grant(permissionRequestRef!!.resources)
                } else {
                    permissionRequestRef?.deny()
                }
                permissionRequestRef = null
            }
        }
    }

    private fun configureCookieManager() {
        val cookieManager = CookieManager.getInstance()
        cookieManager.setAcceptCookie(true)
        cookieManager.setAcceptThirdPartyCookies(webView, true)
    }

    private fun launchIntent(intent: Intent): Boolean {
        return try {
            startActivity(intent)
            true
        } catch (_: Exception) {
            false
        }
    }

    @Throws(IOException::class)
    private fun createImageFile(): File {
        val timeStamp = SimpleDateFormat("yyyyMMdd_HHmmss", Locale.US).format(Date())
        val storageDir = getExternalFilesDir(Environment.DIRECTORY_PICTURES)
        return File.createTempFile("JPEG_${timeStamp}_", ".jpg", storageDir)
    }

    override fun onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack()
        } else {
            super.onBackPressed()
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        webView.saveState(outState)
    }

    override fun onRestoreInstanceState(savedInstanceState: Bundle) {
        super.onRestoreInstanceState(savedInstanceState)
        webView.restoreState(savedInstanceState)
    }

    companion object {
        private const val REQUEST_PERMISSION_MEDIA = 1001
    }
}
