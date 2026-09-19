-keep class ir.swaapin.mobile.** { *; }
-keepclassmembers class ir.swaapin.mobile.** { *; }
-keepattributes *Annotation*
-keepattributes JavascriptInterface
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
-keep class androidx.webkit.** { *; }
-dontwarn androidx.webkit.**
-keep class com.google.firebase.** { *; }
-dontwarn com.google.firebase.**
