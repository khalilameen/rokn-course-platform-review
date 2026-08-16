import React, {useMemo} from 'react';
import {StyleSheet} from 'react-native';
import {SettingRow} from '../../components/settings/SettingRow';
import {PremiumCard, SectionHeading} from '../../components/ui/PremiumUI';
import {Spacing} from '../../constants/designSystem';
import {
  buildSettingsSections,
  type SettingsSectionsProps,
} from './settingsData';

export const SettingsSections = (props: SettingsSectionsProps) => {
  const sections = useMemo(() => buildSettingsSections(props), [props]);
  return (
    <>
      {sections.map(section => (
        <React.Fragment key={section.id}>
          <SectionHeading style={styles.heading} title={section.title} />
          <PremiumCard style={styles.group}>
            {section.rows.map(({id, ...row}) => (
              <SettingRow key={id} {...row} />
            ))}
          </PremiumCard>
        </React.Fragment>
      ))}
    </>
  );
};

const styles = StyleSheet.create({
  heading: {marginTop: Spacing.md, marginBottom: Spacing.xs},
  group: {padding: 0, marginBottom: Spacing.md},
});
