import React, {useEffect, useState} from 'react';
import {
  Image,
  type ImageSourcePropType,
  type ImageStyle,
  StyleSheet,
  type StyleProp,
  View,
} from 'react-native';
import {SvgUri} from 'react-native-svg';

const sourceUri = (source?: ImageSourcePropType) =>
  source && typeof source === 'object' && !Array.isArray(source)
    ? source.uri
    : undefined;

export const isSvgCourseArtwork = (source?: ImageSourcePropType) =>
  /\.svg(?:$|[?#])/i.test(sourceUri(source) || '');

type CourseArtworkProps = {
  fallback: ImageSourcePropType;
  source?: ImageSourcePropType;
  style: StyleProp<ImageStyle>;
};

export const CourseArtwork = ({fallback, source, style}: CourseArtworkProps) => {
  const uri = sourceUri(source);
  const [failed, setFailed] = useState(false);

  useEffect(() => setFailed(false), [uri]);

  if (failed || !source) {
    return <Image source={fallback} style={style} />;
  }

  if (isSvgCourseArtwork(source) && uri) {
    return (
      <View style={[style, styles.svgClip]}>
        <SvgUri
          fallback={<Image source={fallback} style={StyleSheet.absoluteFill} />}
          height="100%"
          onError={() => setFailed(true)}
          preserveAspectRatio="xMidYMid slice"
          uri={uri}
          width="100%"
        />
      </View>
    );
  }

  return (
    <Image onError={() => setFailed(true)} source={source} style={style} />
  );
};

const styles = StyleSheet.create({
  svgClip: {overflow: 'hidden'},
});
