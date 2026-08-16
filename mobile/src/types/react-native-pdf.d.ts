declare module 'react-native-pdf' {
  import * as React from 'react';
  import {StyleProp, ViewStyle} from 'react-native';

  export type PdfSource = {
    uri?: string;
    cache?: boolean;
    headers?: Record<string, string>;
  };

  export type PdfProps = {
    source: PdfSource;
    style?: StyleProp<ViewStyle>;
    trustAllCerts?: boolean;
    page?: number;
    scale?: number;
    minScale?: number;
    maxScale?: number;
    horizontal?: boolean;
    showsHorizontalScrollIndicator?: boolean;
    showsVerticalScrollIndicator?: boolean;
    scrollEnabled?: boolean;
    spacing?: number;
    enablePaging?: boolean;
    enableRTL?: boolean;
    enableAntialiasing?: boolean;
    enableAnnotationRendering?: boolean;
    enableDoubleTapZoom?: boolean;
    fitPolicy?: 0 | 1 | 2;
    singlePage?: boolean;
    onLoadComplete?: (
      numberOfPages: number,
      path: string,
      size: {height: number; width: number},
    ) => void;
    onError?: (error: object) => void;
  };

  export default class Pdf extends React.Component<PdfProps> {}
}
