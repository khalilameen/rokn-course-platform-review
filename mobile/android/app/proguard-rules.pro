# Add project specific ProGuard rules here.
# By default, the flags in this file are appended to flags specified
# in /usr/local/Cellar/android-sdk/24.3.3/tools/proguard/proguard-android.txt
# You can edit the include path and order by changing the proguardFiles
# directive in build.gradle.
#
# For more details, see
#   http://developer.android.com/guide/developing/tools/proguard.html

# React Native discovers methods annotated with ReactMethod at runtime. The
# framework ships consumer rules, but keeping the annotation contract here also
# protects Rokn's in-house native modules when R8 full mode evolves.
-keepclassmembers,allowoptimization class com.rokn.** {
    @com.facebook.react.bridge.ReactMethod <methods>;
}

# Preserve useful source/line metadata in release crash reports. The matching
# R8 map and Hermes source map are copied next to every production artifact.
-keepattributes SourceFile,LineNumberTable
